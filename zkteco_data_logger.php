<?php
/**
 * ZKTeco Data Logger (Fixed)
 * 
 * Simple script to connect to ZKTeco device and log the data structure
 */

// Load dependencies
require_once __DIR__ . '/vendor/autoload.php';

use Rats\Zkteco\Lib\ZKTeco;

// Create logs directory if it doesn't exist
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Setup log file
$logFile = $logDir . '/zkteco_data_' . date('Y-m-d_H-i-s') . '.log';
$fp = fopen($logFile, 'w');

// Load config
$configFile = __DIR__ . '/config.json';
if (!file_exists($configFile)) {
    echo "Error: Config file not found at: $configFile\n";
    exit(1);
}

$config = json_decode(file_get_contents($configFile), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "Error: Invalid JSON in config file\n";
    exit(1);
}

if (empty($config['devices'])) {
    echo "Error: No devices defined in config file\n";
    exit(1);
}

// Process each device in the config
foreach ($config['devices'] as $device) {
    $ip = $device['ip'];
    $port = $device['port'] ?? 4370;
    $name = $device['name'];
    
    echo "Connecting to device: {$name} ({$ip}:{$port})...\n";
    
    writeLog($fp, "Device: {$name} ({$ip}:{$port})");
    
    try {
        // Initialize and connect to ZKTeco device
        $zk = new ZKTeco($ip, $port);
        
        if (!$zk->connect()) {
            echo "Failed to connect to device!\n";
            writeLog($fp, "Failed to connect to device");
            continue;
        }
        
        echo "Connected successfully!\n";
        writeLog($fp, "Connected successfully");
        
        // Get device info
        try {
            $deviceName = $zk->deviceName();
            $serialNumber = $zk->serialNumber();
            $deviceTime = $zk->getTime();
            
            echo "Device Name: {$deviceName}\n";
            echo "Serial Number: {$serialNumber}\n";
            echo "Device Time: {$deviceTime}\n";
            
            writeLog($fp, "Device Info:");
            writeLog($fp, "- Device Name: {$deviceName}");
            writeLog($fp, "- Serial Number: {$serialNumber}");
            writeLog($fp, "- Device Time: {$deviceTime}");
        } catch (\Exception $e) {
            echo "Could not get device info: {$e->getMessage()}\n";
            writeLog($fp, "Could not get device info: {$e->getMessage()}");
        }
        
        // Get users data with better error handling
        try {
            $users = $zk->getUser();
            
            // Ensure users is an array and not empty or null
            if (!is_array($users) || empty($users) || $users === null) {
                echo "No user data returned or invalid format\n";
                writeLog($fp, "No user data returned or invalid format");
                $users = [];
            }
            
            $userCount = count($users);
            echo "Retrieved {$userCount} user records\n";
            writeLog($fp, "User Records: {$userCount}");
            
            if ($userCount > 0 && isset($users[0]) && is_array($users[0])) {
                // Log first user structure
                $firstUser = $users[0];
                $fields = array_keys($firstUser);
                
                echo "User Fields: " . implode(", ", $fields) . "\n";
                writeLog($fp, "User Fields: " . json_encode($fields));
                
                // Log all users in JSON format
                writeLog($fp, "User Data:");
                writeLog($fp, json_encode($users, JSON_PRETTY_PRINT));
                
                // Display sample user
                echo "Sample User Data:\n";
                print_r($firstUser);
            } else {
                echo "No valid user records found\n";
                writeLog($fp, "No valid user records found");
            }
        } catch (\Exception $e) {
            echo "Could not get user data: {$e->getMessage()}\n";
            writeLog($fp, "Could not get user data: {$e->getMessage()}");
        }
        
        // Get attendance data with better error handling
        try {
            $attendance = $zk->getAttendance();
            
            // Ensure attendance is an array and not empty or null
            if (!is_array($attendance) || empty($attendance) || $attendance === null) {
                echo "No attendance data returned or invalid format\n";
                writeLog($fp, "No attendance data returned or invalid format");
                $attendance = [];
            }
            
            $recordCount = count($attendance);
            echo "Retrieved {$recordCount} attendance records\n";
            writeLog($fp, "Attendance Records: {$recordCount}");
            
            if ($recordCount > 0 && isset($attendance[0]) && is_array($attendance[0])) {
                // Log first record structure
                $firstRecord = $attendance[0];
                $fields = array_keys($firstRecord);
                
                echo "Attendance Fields: " . implode(", ", $fields) . "\n";
                writeLog($fp, "Attendance Fields: " . json_encode($fields));
                
                // Log all attendance records in JSON format
                writeLog($fp, "Attendance Data:");
                writeLog($fp, json_encode($attendance, JSON_PRETTY_PRINT));
                
                // Display sample attendance record
                echo "Sample Attendance Record:\n";
                print_r($firstRecord);
            } else {
                echo "No valid attendance records found\n";
                writeLog($fp, "No valid attendance records found");
            }
        } catch (\Exception $e) {
            echo "Could not get attendance data: {$e->getMessage()}\n";
            writeLog($fp, "Could not get attendance data: {$e->getMessage()}");
        }
        
        // Disconnect from device
        $zk->disconnect();
        echo "Disconnected from device\n";
        writeLog($fp, "Disconnected from device");
        
    } catch (\Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        writeLog($fp, "Error: {$e->getMessage()}");
    }
    
    echo "-------------------------------------------\n";
    writeLog($fp, "-------------------------------------------");
}

// Close log file
fclose($fp);
echo "Log file saved to: {$logFile}\n";

/**
 * Write a line to the log file
 */
function writeLog($fp, $message) {
    fwrite($fp, $message . "\n");
}