<?php
/**
 * Employee Data Sync Script
 * 
 * This script syncs employee data from a local source (CSV, Excel, or database)
 * to the HRM system via API.
 */

// Load dependencies
require_once __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

// Set up logging
$logFile = __DIR__ . '/logs/employee_sync_' . date('Y-m-d') . '.log';
$dateFormat = "Y-m-d H:i:s";
$output = "[%datetime%] %level_name%: %message% %context%\n";
$formatter = new LineFormatter($output, $dateFormat);
$stream = new StreamHandler($logFile, Logger::DEBUG);
$stream->setFormatter($formatter);

$logger = new Logger('employee_sync');
$logger->pushHandler($stream);

// Start the script
$logger->info('Employee sync script started');

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
if (!isset($config['api_endpoint']) || !isset($config['api_key'])) {
    $logger->error('Missing required configuration values');
    die("Error: Missing required configuration values in config.json\n");
}

// Initialize HTTP client
$client = new Client([
    'timeout' => 30,
    'verify' => false, // Disable SSL verification for development
    'http_errors' => false
]);

// Source of employee data (configure based on your needs)
$dataSource = $config['data_source'] ?? 'csv';
$sourceFile = $config['source_file'] ?? null;

if (!$sourceFile || !file_exists($sourceFile)) {
    $logger->error('Source file not found: ' . $sourceFile);
    die("Error: Source file not found: $sourceFile\n");
}

// Get employee data from source
$employees = getEmployeeData($dataSource, $sourceFile, $logger);

if (empty($employees)) {
    $logger->warning('No employee data found to sync');
    die("Warning: No employee data found to sync\n");
}

// Sync employees to HRM system
$result = syncEmployees($employees, $config, $client, $logger);

// Display summary
if ($result) {
    echo "Employee sync completed successfully.\n";
    echo "Created: " . $result['summary']['created'] . "\n";
    echo "Updated: " . $result['summary']['updated'] . "\n";
    echo "Skipped: " . $result['summary']['skipped'] . "\n";
    echo "Errors: " . $result['summary']['errors'] . "\n";
    echo "Total: " . $result['summary']['total'] . "\n";
} else {
    echo "Employee sync failed. Check logs for details.\n";
}

$logger->info('Employee sync script completed');

/**
 * Get employee data from source
 */
function getEmployeeData($dataSource, $sourceFile, $logger) {
    $employees = [];
    
    switch ($dataSource) {
        case 'csv':
            $employees = getEmployeeDataFromCsv($sourceFile, $logger);
            break;
        case 'excel':
            $employees = getEmployeeDataFromExcel($sourceFile, $logger);
            break;
        case 'database':
            $employees = getEmployeeDataFromDatabase($sourceFile, $logger);
            break;
        default:
            $logger->error('Unsupported data source: ' . $dataSource);
            die("Error: Unsupported data source: $dataSource\n");
    }
    
    return $employees;
}

/**
 * Get employee data from CSV file
 */
function getEmployeeDataFromCsv($sourceFile, $logger) {
    $employees = [];
    
    if (($handle = fopen($sourceFile, "r")) !== FALSE) {
        // Read header row
        $header = fgetcsv($handle, 1000, ",");
        
        if (!$header) {
            $logger->error('Failed to read CSV header');
            return $employees;
        }
        
        // Convert header to lowercase for case-insensitive matching
        $header = array_map('strtolower', $header);
        
        // Required fields
        $requiredFields = ['employee_id', 'first_name'];
        foreach ($requiredFields as $field) {
            if (!in_array($field, $header)) {
                $logger->error("Required field missing in CSV: $field");
                fclose($handle);
                return $employees;
            }
        }
        
        // Process data rows
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $employee = [];
            
            foreach ($header as $index => $field) {
                if (isset($data[$index])) {
                    $employee[$field] = $data[$index];
                }
            }
            
            // Validate required fields
            if (empty($employee['employee_id']) || empty($employee['first_name'])) {
                $logger->warning('Skipping row with missing required data');
                continue;
            }
            
            $employees[] = $employee;
        }
        
        fclose($handle);
    } else {
        $logger->error('Failed to open CSV file: ' . $sourceFile);
    }
    
    return $employees;
}

/**
 * Get employee data from Excel file
 */
function getEmployeeDataFromExcel($sourceFile, $logger) {
    try {
        // Check if PhpSpreadsheet is available
        if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            $logger->error('PhpSpreadsheet not installed. Run: composer require phpoffice/phpspreadsheet');
            return [];
        }
        
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($sourceFile);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($sourceFile);
        $worksheet = $spreadsheet->getActiveSheet();
        
        $employees = [];
        $headerRow = [];
        $rowIndex = 1;
        
        // Get headers
        foreach ($worksheet->getRowIterator(1, 1) as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            
            foreach ($cellIterator as $cell) {
                $headerRow[] = strtolower(trim($cell->getValue()));
            }
        }
        
        // Required fields
        $requiredFields = ['employee_id', 'first_name'];
        foreach ($requiredFields as $field) {
            if (!in_array($field, $headerRow)) {
                $logger->error("Required field missing in Excel: $field");
                return $employees;
            }
        }
        
        // Process data rows
        foreach ($worksheet->getRowIterator(2) as $row) {
            $rowData = [];
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $colIndex = 0;
            
            foreach ($cellIterator as $cell) {
                if (isset($headerRow[$colIndex])) {
                    $rowData[$headerRow[$colIndex]] = trim($cell->getValue());
                }
                $colIndex++;
            }
            
            // Validate required fields
            if (empty($rowData['employee_id']) || empty($rowData['first_name'])) {
                $logger->warning('Skipping row with missing required data');
                continue;
            }
            
            $employees[] = $rowData;
        }
        
        return $employees;
        
    } catch (\Exception $e) {
        $logger->error('Excel parsing error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get employee data from database
 */
function getEmployeeDataFromDatabase($configFile, $logger) {
    try {
        $dbConfig = json_decode(file_get_contents($configFile), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $logger->error('Invalid JSON in database config file');
            return [];
        }
        
        if (!isset($dbConfig['host']) || !isset($dbConfig['username']) || 
            !isset($dbConfig['database']) || !isset($dbConfig['query'])) {
            $logger->error('Missing required database configuration');
            return [];
        }
        
        $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'] ?? '', $options);
        
        $stmt = $pdo->query($dbConfig['query']);
        $employees = $stmt->fetchAll();
        
        return $employees;
        
    } catch (\Exception $e) {
        $logger->error('Database error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Sync employees to HRM system
 */
function syncEmployees($employees, $config, $client, $logger) {
    $logger->info("Syncing " . count($employees) . " employees to HRM system");
    
    try {
        $response = $client->post($config['api_endpoint'], [
            'headers' => [
                'Authorization' => 'Bearer ' . $config['api_key'],
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ],
            'json' => [
                'employees' => $employees
            ]
        ]);
        
        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();
        $result = json_decode($body, true);
        
        if ($statusCode >= 200 && $statusCode < 300 && isset($result['status']) && $result['status']) {
            $logger->info("API request successful: {$result['message']}");
            return $result;
        } else {
            $logger->error("API request failed with status code {$statusCode}: " . ($result['message'] ?? $body));
            return null;
        }
    } catch (RequestException $e) {
        $logger->error("API request exception: " . $e->getMessage());
        return null;
    }
}