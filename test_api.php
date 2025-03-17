<?php
/**
 * ZKTeco Agent Script
 * 
 * This script connects to ZKTeco devices in a local network,
 * retrieves attendance data, and sends it to a Laravel API.
 */

// Load dependencies
require_once __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Rats\Zkteco\Lib\ZKTeco;

// Set up logging
$logFile = __DIR__ . '/logs/zkteco_agent_' . date('Y-m-d') . '.log';
$logHandler = fopen($logFile, 'a+');

// Log function
function writeLog($message, $logHandler) {
    $timestamp = date('Y-m-d H:i:s');
    fwrite($logHandler, "[$timestamp] $message\n");
    echo "[$timestamp] $message\n";
}

// Start the script
writeLog('ZKTeco Agent script started', $logHandler);

// Check if config file exists
$configFile = __DIR__ . '/config.json';
if (!file_exists($configFile)) {
    writeLog("Error: Config file not found at: $configFile", $logHandler);
    die("Error: Config file not found at: $configFile\n");
}

// Load config
$config = json_decode(file_get_contents($configFile), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    writeLog('Invalid JSON in config file: ' . json_last_error_msg(), $logHandler);
    die("Error: Invalid JSON in config file\n");
}

// Check if required config values are present
if (!isset($config['devices']) || !isset($config['api_endpoint']) || !isset($config['api_key'])) {
    writeLog('Missing required configuration values', $logHandler);
    die("Error: Missing required configuration values in config.json\n");
}

// Enable debug mode if set
$debug = isset($config['debug']) ? $config['debug'] : false;

// Initialize HTTP client
$client = new Client([
    'timeout' => 30,
    'verify' => false, // Disable SSL verification for development
    'http_errors' => false
]);

// Process each device
foreach ($config['devices'] as $device) {
    processDevice($device, $config, $client, $logHandler, $debug);
}

writeLog('ZKTeco Agent script completed', $logHandler);
fclose($logHandler);

/**
 * Process a single ZKTeco device
 */
function processDevice($device, $config, $client, $logHandler, $debug) {
    if (!isset($device['ip']) || !isset($device['id']) || !isset($device['name'])) {
        writeLog('Invalid device configuration: ' . json_encode($device), $logHandler);
        return;
    }
    
    $ip = $device['ip'];
    $port = isset($device['port']) ? $device['port'] : 4370;
    writeLog("Processing device: {$device['name']} ({$ip}:{$port})", $logHandler);
    
    try {
        // Initialize ZKTeco device
        $zk = new ZKTeco($ip, $port);
        
        // Try to connect to the device
        if (!$zk->connect()) {
            writeLog("Failed to connect to device: {$device['name']} ({$ip}:{$port})", $logHandler);
            return;
        }
        
        writeLog("Connected to device: {$device['name']} ({$ip}:{$port})", $logHandler);
        
        // Get device information
        if ($debug) {
            try {
                $deviceName = $zk->deviceName();
                $serialNumber = $zk->serialNumber();
                $deviceTime = $zk->getTime();
                writeLog("Device info: Name=$deviceName, SN=$serialNumber, Time=$deviceTime", $logHandler);
            } catch (\Exception $e) {
                writeLog("Could not retrieve device info: " . $e->getMessage(), $logHandler);
            }
        }
        
        // Get attendance records
        $attendance = $zk->getAttendance();
        $recordCount = count($attendance);
        writeLog("Retrieved {$recordCount} attendance records", $logHandler);
        
        // Disconnect from device
        $zk->disconnect();
        
        // If no records, skip sending to API
        if ($recordCount === 0) {
            writeLog("No attendance records to sync", $logHandler);
            return;
        }
        
        // Send data to API
        sendToApi($attendance, $device, $config, $client, $logHandler);
        
        // Clear attendance if needed
        if (isset($config['clear_after_sync']) && $config['clear_after_sync']) {
            clearAttendance($zk, $device, $logHandler);
        }
        
    } catch (\Exception $e) {
        writeLog("Error processing device {$device['name']}: " . $e->getMessage(), $logHandler);
    }
}

/**
 * Send attendance data to the API
 */
function sendToApi($attendance, $device, $config, $client, $logHandler) {
    writeLog("Sending data to API: {$config['api_endpoint']}", $logHandler);
    
    try {
        $response = $client->post($config['api_endpoint'], [
            'headers' => [
                'Authorization' => 'Bearer ' . $config['api_key'],
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ],
            'json' => [
                'device_id' => $device['id'],
                'device_name' => $device['name'],
                'device_ip' => $device['ip'],
                'attendance_data' => $attendance
            ]
        ]);
        
        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();
        $result = json_decode($body, true);
        
        if ($statusCode >= 200 && $statusCode < 300 && isset($result['status']) && $result['status']) {
            writeLog("API request successful: " . (isset($result['message']) ? $result['message'] : 'No message'), $logHandler);
            return $result;
        } else {
            writeLog("API request failed with status code {$statusCode}: " . (isset($result['message']) ? $result['message'] : $body), $logHandler);
            return null;
        }
    } catch (\Exception $e) {
        writeLog("API request exception: " . $e->getMessage(), $logHandler);
        return null;
    }
}

/**
 * Clear attendance data from the device
 */
function clearAttendance($zk, $device, $logHandler) {
    try {
        writeLog("Clearing attendance data from device: {$device['name']}", $logHandler);
        
        if ($zk->connect()) {
            $result = $zk->clearAttendance();
            $zk->disconnect();
            
            if ($result) {
                writeLog("Successfully cleared attendance data", $logHandler);
            } else {
                writeLog("Failed to clear attendance data", $logHandler);
            }
        } else {
            writeLog("Could not connect to device to clear attendance data", $logHandler);
        }
    } catch (\Exception $e) {
        writeLog("Error clearing attendance: " . $e->getMessage(), $logHandler);
    }
}