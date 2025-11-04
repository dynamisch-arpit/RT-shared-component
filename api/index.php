<?php

// Include Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Simple router
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Remove query string and base path
$path = parse_url($requestUri, PHP_URL_PATH);
$path = str_replace('/index.php', '', $path);
$path = str_replace('/api', '', $path);
$path = trim($path, '/');

// Route handling
try {
    switch ($path) {
        case '':
        case 'info':
            handleApiInfo();
            break;
            
        default:
            sendError(404, 'Endpoint not found');
            break;
    }
} catch (Exception $e) {
    sendError(500, 'Internal server error', $e->getMessage());
}

function handleApiInfo() {
    $info = [
        'api' => 'AWS SQS FIFO Queue API',
        'version' => '1.0.0',
        'endpoints' => [
            'GET /api/info' => 'API information',
            'POST /api/audit-log' => 'Send audit log message to queue',
            'GET /api/queues' => 'List available queues',
            'POST /api/queues' => 'Create new queue'
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    sendResponse(200, $info);
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
