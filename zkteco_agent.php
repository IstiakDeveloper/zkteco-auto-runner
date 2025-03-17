<?php
/**
 * ZKTeco Agent Script
 * 
 * This script connects to ZKTeco devices in a local network,
 * retrieves attendance data and user data, and sends it to a Laravel API.
 */

// Load dependencies
require_once __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Rats\Zkteco\Lib\ZKTeco;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

// Set up logging
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logFile = $logDir . '/zkteco_agent_' . date('Y-m-d') . '.log';
$dateFormat = "Y-m-d H:i:s";
$output = "[%datetime%] %level_name%: %message% %context%\n";
$formatter = new LineFormatter($output, $dateFormat);
$stream = new StreamHandler($logFile, Logger::DEBUG);
$stream->setFormatter($formatter);

$logger = new Logger('zkteco_agent');
$logger->pushHandler($stream);

// Start the script
$logger->info('ZKTeco Agent script started');

// Check if config file exists
$configFile = __DIR__ . '/config.json';
if (!file_exists($configFile)) {
    $logger->error('Config file not found at: ' . $configFile);
    die("Error: Config file not found at: $configFile\n");
}

// Load config
$config = json_decode(file_get_contents($configFile), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $logger->error('Invalid JSON in config file: ' . json_last_error_msg());
    die("Error: Invalid JSON in config file\n");
}

// Check if required config values are present
if (!isset($config['devices']) || !isset($config['api_endpoint']) || !isset($config['api_key'])) {
    $logger->error('Missing required configuration values');
    die("Error: Missing required configuration values in config.json\n");
}

// Enable debug mode if set
$debug = isset($config['debug']) && $config['debug'];

// Initialize HTTP client
$client = new Client([
    'timeout' => 30,
    'verify' => false, // Disable SSL verification for development
    'http_errors' => false
]);

// Process each device
$totalDevices = count($config['devices']);
$successDevices = 0;
$failedDevices = 0;

foreach ($config['devices'] as $device) {
    $result = processDevice($device, $config, $client, $logger, $debug);
    if ($result) {
        $successDevices++;
    } else {
        $failedDevices++;
    }
}

$logger->info('ZKTeco Agent script completed', [
    'total_devices' => $totalDevices,
    'success_devices' => $successDevices,
    'failed_devices' => $failedDevices
]);

if ($debug) {
    echo "ZKTeco Agent script completed\n";
    echo "Devices processed: {$totalDevices}\n";
    echo "Successful: {$successDevices}\n";
    echo "Failed: {$failedDevices}\n";
}

/**
 * Process a single ZKTeco device
 */
function processDevice($device, $config, $client, $logger, $debug) {
    if (!isset($device['ip']) || !isset($device['id']) || !isset($device['name'])) {
        $logger->error('Invalid device configuration', $device);
        return false;
    }
    
    $ip = $device['ip'];
    $port = $device['port'] ?? 4370;
    $logger->info("Processing device: {$device['name']} ({$ip}:{$port})");
    
    if ($debug) {
        echo "Processing device: {$device['name']} ({$ip}:{$port})\n";
    }
    
    try {
        // Initialize ZKTeco device
        $zk = new ZKTeco($ip, $port);
        
        // Try to connect to the device
        if (!$zk->connect()) {
            $logger->error("Failed to connect to device: {$device['name']} ({$ip}:{$port})");
            return false;
        }
        
        $logger->info("Connected to device: {$device['name']} ({$ip}:{$port})");
        
        // Get device information
        $deviceInfo = [];
        if ($debug) {
            try {
                $deviceInfo = [
                    'device_name' => $zk->deviceName(),
                    'serial_number' => $zk->serialNumber(),
                    'device_time' => $zk->getTime()
                ];
                $logger->info("Device info: ", $deviceInfo);
                
                echo "Device Name: {$deviceInfo['device_name']}\n";
                echo "Serial Number: {$deviceInfo['serial_number']}\n";
                echo "Device Time: {$deviceInfo['device_time']}\n";
            } catch (\Exception $e) {
                $logger->warning("Could not retrieve device info: " . $e->getMessage());
            }
        }
        
        // Get user data from device
        $users = [];
        try {
            $users = $zk->getUser();
            $userCount = count($users);
            $logger->info("Retrieved {$userCount} user records");
            
            if ($debug) {
                echo "Retrieved {$userCount} user records\n";
            }
        } catch (\Exception $e) {
            $logger->warning("Could not retrieve user data: " . $e->getMessage());
        }
        
        // Get attendance records
        $attendance = $zk->getAttendance();
        $recordCount = count($attendance);
        $logger->info("Retrieved {$recordCount} attendance records");
        
        if ($debug) {
            echo "Retrieved {$recordCount} attendance records\n";
            
            if ($recordCount > 0 && $debug) {
                echo "Sample attendance record:\n";
                print_r($attendance[0]);
            }
        }
        
        // Disconnect from device
        $zk->disconnect();
        
        // If no records and no users, skip sending to API
        if ($recordCount === 0 && count($users) === 0) {
            $logger->info("No data to sync");
            
            if ($debug) {
                echo "No data to sync\n";
            }
            
            return true; // Still count as successful since we connected properly
        }
        
        // Prepare device data
        $deviceData = [
            'id' => $device['id'],
            'name' => $device['name'],
            'ip' => $device['ip'],
            'port' => $port
        ];
        
        // Add serial number if available
        if (!empty($deviceInfo) && isset($deviceInfo['serial_number'])) {
            $deviceData['serial_number'] = $deviceInfo['serial_number'];
        }
        
        // Send data to API
        $apiResult = sendToApi($attendance, $users, $deviceData, $config, $client, $logger, $debug);
        
        // Clear attendance if requested and there are records to clear
        if ($apiResult && isset($config['clear_after_sync']) && $config['clear_after_sync'] && $recordCount > 0) {
            clearAttendance($device, $zk, $logger, $debug);
        }
        
        return $apiResult;
        
    } catch (\Exception $e) {
        $logger->error("Error processing device {$device['name']}: " . $e->getMessage(), [
            'exception' => get_class($e),
            'trace' => $e->getTraceAsString()
        ]);
        
        if ($debug) {
            echo "Error processing device {$device['name']}: " . $e->getMessage() . "\n";
        }
        
        return false;
    }
}

/**
 * Send attendance and user data to the API
 */
function sendToApi($attendance, $users, $device, $config, $client, $logger, $debug) {
    $logger->info("Sending data to API: {$config['api_endpoint']}");
    
    if ($debug) {
        echo "Sending data to API: {$config['api_endpoint']}\n";
    }
    
    $payload = [
        'device_id' => $device['id'],
        'device_name' => $device['name'],
        'device_ip' => $device['ip']
    ];
    
    // Add serial number if available
    if (isset($device['serial_number'])) {
        $payload['serial_number'] = $device['serial_number'];
    }
    
    // Only add attendance data if there are records
    if (!empty($attendance)) {
        $payload['attendance_data'] = $attendance;
    }
    
    // Only add user data if there are users
    if (!empty($users)) {
        $payload['user_data'] = $users;
    }
    
    try {
        $response = $client->post($config['api_endpoint'], [
            'headers' => [
                'Authorization' => 'Bearer ' . $config['api_key'],
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ],
            'json' => $payload
        ]);
        
        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();
        $result = json_decode($body, true);
        
        if ($statusCode >= 200 && $statusCode < 300 && isset($result['status']) && $result['status']) {
            $logger->info("API request successful: {$result['message']}");
            
            if ($debug) {
                echo "API request successful: {$result['message']}\n";
                
                if (isset($result['summary'])) {
                    echo "Summary: ";
                    print_r($result['summary']);
                }
            }
            
            return true;
        } else {
            $errorMsg = $result['message'] ?? $body;
            $logger->error("API request failed with status code {$statusCode}: {$errorMsg}");
            
            if ($debug) {
                echo "API request failed with status code {$statusCode}: {$errorMsg}\n";
                
                if (isset($result['errors'])) {
                    echo "Errors: ";
                    print_r($result['errors']);
                }
            }
            
            return false;
        }
    } catch (RequestException $e) {
        $logger->error("API request exception: " . $e->getMessage());
        
        if ($debug) {
            echo "API request exception: " . $e->getMessage() . "\n";
        }
        
        return false;
    }
}

/**
 * Clear attendance data from the device
 */
function clearAttendance($device, $zk, $logger, $debug) {
    $logger->info("Clearing attendance data from device: {$device['name']}");
    
    if ($debug) {
        echo "Clearing attendance data from device: {$device['name']}\n";
    }
    
    try {
        if ($zk->connect()) {
            $result = $zk->clearAttendance();
            $zk->disconnect();
            
            if ($result) {
                $logger->info("Successfully cleared attendance data");
                
                if ($debug) {
                    echo "Successfully cleared attendance data\n";
                }
                
                return true;
            } else {
                $logger->error("Failed to clear attendance data");
                
                if ($debug) {
                    echo "Failed to clear attendance data\n";
                }
                
                return false;
            }
        } else {
            $logger->error("Could not connect to device to clear attendance data");
            
            if ($debug) {
                echo "Could not connect to device to clear attendance data\n";
            }
            
            return false;
        }
    } catch (\Exception $e) {
        $logger->error("Error clearing attendance: " . $e->getMessage());
        
        if ($debug) {
            echo "Error clearing attendance: " . $e->getMessage() . "\n";
        }
        
        return false;
    }
}