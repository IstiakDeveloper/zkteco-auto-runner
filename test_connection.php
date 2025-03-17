<?php
/**
 * ZKTeco Connection Test Script
 * 
 * Use this script to test the connection to your ZKTeco device
 */

require_once __DIR__ . '/vendor/autoload.php';

use Rats\Zkteco\Lib\ZKTeco;

// Load config
$configFile = __DIR__ . '/config.json';
if (!file_exists($configFile)) {
    die("Error: Config file not found at: $configFile\n");
}

$config = json_decode(file_get_contents($configFile), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error: Invalid JSON in config file\n");
}

echo "ZKTeco Device Connection Test\n";
echo "============================\n\n";

foreach ($config['devices'] as $device) {
    // Default port to 4370 if not set
    $port = isset($device['port']) ? $device['port'] : 4370;
    
    echo "Testing connection to device: {$device['name']} ({$device['ip']}:{$port})\n";
    
    $zk = new ZKTeco($device['ip'], $port);
    
    echo "Attempting connection... ";
    if ($zk->connect()) {
        echo "SUCCESS!\n\n";
        
        echo "Device Information:\n";
        echo "- Device Name: " . $zk->deviceName() . "\n";
        echo "- Serial Number: " . $zk->serialNumber() . "\n";
        echo "- Device Time: " . $zk->getTime() . "\n\n";
        
        echo "Retrieving attendance records... ";
        $attendance = $zk->getAttendance();
        echo "Found " . count($attendance) . " records.\n\n";
        
        if (count($attendance) > 0) {
            echo "Last 5 attendance records:\n";
            $lastRecords = array_slice($attendance, -5);
            
            echo str_pad("User ID", 10) . " | " . str_pad("State", 5) . " | " . "Timestamp\n";
            echo str_repeat("-", 60) . "\n";
            
            foreach ($lastRecords as $record) {
                echo str_pad($record['id'], 10) . " | " . 
                     str_pad($record['state'], 5) . " | " . 
                     $record['timestamp'] . "\n";
            }
            echo "\n";
        }
        
        $zk->disconnect();
        echo "Disconnected from device.\n";
    } else {
        echo "FAILED!\n";
        echo "Could not connect to the device. Please check the IP address and port.\n";
    }
    
    echo "\n" . str_repeat("=", 50) . "\n\n";
}

echo "Test completed.\n";