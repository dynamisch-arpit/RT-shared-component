<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../vendor/autoload.php';

use RT\SharedComponents\RT_Shared_Components;

// Configuration
$config = [
    'aws' => [
        'version' => 'latest',
        'region' => $_ENV['AWS_DEFAULT_REGION'] ?? 'us-east-1',
        'credentials' => [
            'key' => $_ENV['AWS_ACCESS_KEY_ID'] ?? 'your-aws-access-key',
            'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'] ?? 'your-aws-secret-key'
        ]
    ],
    'sqs' => [
        'queue_name' => 'audit-log-queue.fifo',
        'queue_url' => ''
    ],
    'database' => [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'database' => $_ENV['DB_DATABASE'] ?? 'your_database',
        'username' => $_ENV['DB_USERNAME'] ?? 'your_username',
        'password' => $_ENV['DB_PASSWORD'] ?? 'your_password'
    ]
];

// Initialize RT Shared Components
$rtComponents = new RT_Shared_Components($config);

// Simple router
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Remove query string and base path
$path = parse_url($requestUri, PHP_URL_PATH);
$path = str_replace('/RT-shared-components/api', '', $path);
$path = str_replace('/rt_api.php', '', $path);
$path = trim($path, '/');

// Route handling
try {
    switch ($path) {
        // System Management
        case 'api/system/status':
            if ($requestMethod === 'GET') {
                handleSystemStatus();
            } else {
                sendError(405, 'Method not allowed. Use GET.');
            }
            break;
            
        case 'api/health':
            if ($requestMethod === 'GET') {
                handleHealthCheck();
            } else {
                sendError(405, 'Method not allowed. Use GET.');
            }
            break;
            
        case 'api/system/config':
            if ($requestMethod === 'GET') {
                handleSystemConfig();
            } else {
                sendError(405, 'Method not allowed. Use GET.');
            }
            break;
            
        default:
            sendError(404, 'Endpoint not found');
            break;
    }
} catch (Exception $e) {
    sendError(500, 'Internal server error', $e->getMessage());
}

// System Management Functions
function handleSystemStatus() {
    global $rtComponents;
    
    $status = $rtComponents->getStatus();
    
    sendResponse(200, [
        'system' => 'RT Shared Components',
        'version' => '1.0.0',
        'status' => 'operational',
        'components' => $status,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

function handleHealthCheck() {
    sendResponse(200, [
        'status' => 'healthy',
        'service' => 'RT Shared Components API',
        'version' => '1.0.0',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

function handleSystemConfig() {
    global $config;
    
    // Sanitize config for security
    $sanitizedConfig = [
        'aws' => [
            'region' => $config['aws']['region'],
            'version' => $config['aws']['version']
        ],
        'sqs' => [
            'queue_name' => $config['sqs']['queue_name']
        ],
        'database' => [
            'host' => $config['database']['host'],
            'database' => $config['database']['database']
        ]
    ];
    
    sendResponse(200, [
        'configuration' => $sanitizedConfig
    ]);
}

function sendResponse($statusCode, $data) {
    http_response_code($statusCode);
    echo json_encode([
        'success' => true,
        'status' => $statusCode,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
}

function sendError($statusCode, $message, $details = null) {
    http_response_code($statusCode);
    $error = [
        'success' => false,
        'status' => $statusCode,
        'error' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if ($details !== null) {
        $error['details'] = $details;
    }
    
    echo json_encode($error, JSON_PRETTY_PRINT);
}
?>
