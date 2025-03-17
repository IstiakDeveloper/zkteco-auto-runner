<?php
/**
 * Branch Employees to ZKTeco Device Push Script (Fixed Version)
 * 
 * This script gets employees for a specific branch and pushes them 
 * directly to a ZKTeco device using the device's IP from config.json
 */

// Load dependencies
require_once __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Rats\Zkteco\Lib\ZKTeco;

// Load ZKTeco device config
$configFile = __DIR__ . '/config.json';
if (!file_exists($configFile)) {
    die("Error: Config file not found at: $configFile\n");
}

$config = json_decode(file_get_contents($configFile), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error: Invalid JSON in config file\n");
}

if (empty($config['devices'])) {
    die("Error: No devices defined in config file\n");
}

// Configure API
$apiEndpoint = 'https://hrm.mousumibd.org/api/';
$apiKey = 'AWSKJSKJ934895395834985834958345';

// Initialize HTTP client
$client = new Client([
    'base_uri' => $apiEndpoint,
    'timeout' => 30,
    'verify' => false, 
    'http_errors' => false,
    'headers' => [
        'Authorization' => 'Bearer ' . $apiKey,
        'Accept' => 'application/json',
        'Content-Type' => 'application/json'
    ]
]);

// Parse command line arguments
$options = getopt('b:d:p', ['branch:', 'device:', 'push']);
$branchId = $options['b'] ?? $options['branch'] ?? null;
$deviceIndex = $options['d'] ?? $options['device'] ?? 0; // Default to first device
$push = isset($options['p']) || isset($options['push']);

if (!$branchId) {
    echo "Error: Branch ID (-b, --branch) is required.\n";
    echo "Usage: php fixed_device_push.php -b BRANCH_ID [-d DEVICE_INDEX] [-p]\n";
    echo "Options:\n";
    echo "  -b, --branch    Branch ID to get employees\n";
    echo "  -d, --device    Device index from config.json (defaults to 0, first device)\n";
    echo "  -p, --push      Push employees to the device\n";
    exit(1);
}

// Select device from config
if (!isset($config['devices'][$deviceIndex])) {
    echo "Error: Device index {$deviceIndex} not found in config.json\n";
    echo "Available devices:\n";
    foreach ($config['devices'] as $idx => $device) {
        echo "  {$idx}: {$device['name']} ({$device['ip']})\n";
    }
    exit(1);
}

$device = $config['devices'][$deviceIndex];
$deviceIp = $device['ip'];
$devicePort = $device['port'] ?? 4370;

echo "Selected device: {$device['name']} ({$deviceIp}:{$devicePort})\n\n";

try {
    // Get employees for the branch
    $response = $client->get("branch/{$branchId}/employees");
    $result = json_decode((string) $response->getBody(), true);
    
    if ($response->getStatusCode() != 200 || !isset($result['status']) || !$result['status']) {
        echo "Error getting employees for branch {$branchId}: " . ($result['message'] ?? 'Unknown error') . "\n";
        echo "Response: " . (string) $response->getBody() . "\n";
        exit(1);
    }
    
    echo "Retrieved " . $result['total'] . " employees for branch: " . $result['branch']['name'] . "\n\n";
    
    // Display employees
    displayEmployees($result['employees']);
    
    // Push employees to device if requested
    if ($push) {
        // Push directly to device using Rats ZKTeco package
        pushEmployeesToDevice($deviceIp, $devicePort, $result['employees']);
    } else {
        echo "Use -p flag to push these employees to the device.\n";
    }
} catch (RequestException $e) {
    echo "API request failed: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Display employees in a table format
 */
function displayEmployees($employees) {
    if (empty($employees)) {
        echo "No employees found.\n";
        return;
    }
    
    // Initialize column widths with minimum values
    $idWidth = 2;
    $nameWidth = 4;
    $userIdWidth = 7;
    $deptWidth = 10;
    
    // Only calculate max widths if there are values
    if (!empty(array_column($employees, 'id'))) {
        $idWidth = max(array_map('strlen', array_column($employees, 'id')));
    }
    
    if (!empty(array_column($employees, 'name'))) {
        $nameWidth = max(array_map('strlen', array_column($employees, 'name')));
    }
    
    // Filter out null values before calculating max length
    $userIds = array_filter(array_column($employees, 'user_id'), function($value) {
        return $value !== null && $value !== '';
    });
    if (!empty($userIds)) {
        $userIdWidth = max(array_map('strlen', $userIds));
    }
    
    $departments = array_filter(array_column($employees, 'department'), function($value) {
        return $value !== null && $value !== '';
    });
    if (!empty($departments)) {
        $deptWidth = max(array_map('strlen', $departments));
    }
    
    // Print header
    echo str_pad('ID', $idWidth + 2) . " | ";
    echo str_pad('Name', $nameWidth + 2) . " | ";
    echo str_pad('User ID', $userIdWidth + 2) . " | ";
    echo str_pad('Department', $deptWidth + 2) . "\n";
    
    // Print separator
    echo str_repeat('-', $idWidth + 2) . "-+-";
    echo str_repeat('-', $nameWidth + 2) . "-+-";
    echo str_repeat('-', $userIdWidth + 2) . "-+-";
    echo str_repeat('-', $deptWidth + 2) . "\n";
    
    // Print employees
    foreach ($employees as $employee) {
        $userId = $employee['user_id'] ?? 'N/A';
        $department = $employee['department'] ?? 'N/A';
        
        echo str_pad($employee['id'], $idWidth + 2) . " | ";
        echo str_pad($employee['name'], $nameWidth + 2) . " | ";
        echo str_pad($userId, $userIdWidth + 2) . " | ";
        echo str_pad($department, $deptWidth + 2) . "\n";
    }
    
    echo "\n";
}

/**
 * Push employees directly to ZKTeco device using the Rats ZKTeco library
 */
function pushEmployeesToDevice($deviceIp, $devicePort, $employees) {
    echo "Pushing employees directly to device at {$deviceIp}:{$devicePort}...\n";
    
    try {
        $zk = new ZKTeco($deviceIp, $devicePort);
        
        echo "Attempting connection... ";
        $connected = $zk->connect();
        
        if (!$connected) {
            echo "FAILED!\n";
            echo "Could not connect to the device. Please check the IP address and port.\n";
            return;
        }
        
        echo "SUCCESS!\n\n";
        
        // Display device info
        echo "Device Information:\n";
        echo "- Device Name: " . $zk->deviceName() . "\n";
        echo "- Serial Number: " . $zk->serialNumber() . "\n";
        echo "- Device Time: " . $zk->getTime() . "\n\n";
        
        // Clear existing users if needed
        if (confirm("Do you want to clear all existing users from the device?")) {
            echo "Clearing all users from device...\n";
            $result = $zk->clearUsers();
            if ($result) {
                echo "All users cleared successfully.\n";
            } else {
                echo "Failed to clear users.\n";
            }
        }
        
        // Set users
        $success = 0;
        $failed = 0;
        
        foreach ($employees as $employee) {
            // Generate a UID if employee doesn't have a biometric ID
            $uid = !empty($employee['user_id']) && $employee['user_id'] !== 'N/A' 
                 ? $employee['user_id'] 
                 : generateUid($employee['id']);
            
            echo "Adding user: {$employee['name']} (ID: {$employee['id']}, Generated UID: {$uid})... ";
            
            try {
                $result = $zk->setUser(
                    $uid,                    
                    $employee['id'],         
                    $employee['name'],
                    '',              
                    0,                       
                    0                     
                );
                
                if ($result) {
                    echo "Success!\n";
                    $success++;
                } else {
                    echo "Failed!\n";
                    $failed++;
                }
            } catch (\Exception $e) {
                echo "Error: " . $e->getMessage() . "\n";
                $failed++;
            }
        }
        
        echo "\nFinished pushing employees to device.\n";
        echo "Success: {$success}\n";
        echo "Failed: {$failed}\n";
        echo "Total processed: " . count($employees) . "\n";
        
        $zk->disconnect();
        echo "Disconnected from device.\n";
        
    } catch (\Exception $e) {
        echo "Error communicating with device: " . $e->getMessage() . "\n";
    }
}

/**
 * Generate a UID from employee ID
 */
function generateUid($employeeId) {
    // Remove any non-numeric characters
    $numericId = preg_replace('/[^0-9]/', '', $employeeId);
    
    // If empty or too short, use a default starting number + random
    if (empty($numericId) || strlen($numericId) < 2) {
        return '1' . str_pad(mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT);
    }
    
    // If longer than 5 digits, truncate to 5 digits
    if (strlen($numericId) > 5) {
        $numericId = substr($numericId, 0, 5);
    }
    
    // Pad with zeros if needed to make 5 digits
    return str_pad($numericId, 5, '0', STR_PAD_LEFT);
}

/**
 * Simple confirmation prompt
 */
function confirm($message) {
    echo $message . " (y/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    return strtolower(trim($line)) === 'y';
}