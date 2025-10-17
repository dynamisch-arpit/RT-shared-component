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
        // Queue Management
        case 'api/queue/publish':
            if ($requestMethod === 'POST') {
                handlePublishAuditEvent();
            } else {
                sendError(405, 'Method not allowed. Use POST.');
            }
            break;
            
        case 'api/queue/publish-batch':
            if ($requestMethod === 'POST') {
                handlePublishBatchAuditEvents();
            } else {
                sendError(405, 'Method not allowed. Use POST.');
            }
            break;
            
        case 'api/queue/consume':
            if ($requestMethod === 'GET') {
                handleConsumeMessages();
            } else {
                sendError(405, 'Method not allowed. Use GET.');
            }
            break;
            
        case 'api/queue/stats':
            if ($requestMethod === 'GET') {
                handleQueueStats();
            } else {
                sendError(405, 'Method not allowed. Use GET.');
            }
            break;
            
        // Audit Trail Management
        case 'api/audit/process-direct':
            if ($requestMethod === 'POST') {
                handleProcessAuditDirect();
            } else {
                sendError(405, 'Method not allowed. Use POST.');
            }
            break;
            
        case 'api/audit/trail':
            if ($requestMethod === 'GET') {
                handleGetAuditTrail();
            } else {
                sendError(405, 'Method not allowed. Use GET.');
            }
            break;
            
        case 'api/audit/trail/date-range':
            if ($requestMethod === 'GET') {
                handleGetAuditTrailByDateRange();
            } else {
                sendError(405, 'Method not allowed. Use GET.');
            }
            break;
            
        case 'api/audit/cleanup':
            if ($requestMethod === 'DELETE') {
                handleCleanupAuditLogs();
            } else {
                sendError(405, 'Method not allowed. Use DELETE.');
            }
            break;
            
        // Database Services
        case 'api/db/insert':
            if ($requestMethod === 'POST') {
                handleDbInsert();
            } else {
                sendError(405, 'Method not allowed. Use POST.');
            }
            break;
            
        case 'api/db/update':
            if ($requestMethod === 'PUT') {
                handleDbUpdate();
            } else {
                sendError(405, 'Method not allowed. Use PUT.');
            }
            break;
            
        case 'api/db/select':
            if ($requestMethod === 'POST') {
                handleDbSelect();
            } else {
                sendError(405, 'Method not allowed. Use POST.');
            }
            break;
            
        case 'api/db/delete':
            if ($requestMethod === 'DELETE') {
                handleDbDelete();
            } else {
                sendError(405, 'Method not allowed. Use DELETE.');
            }
            break;
            
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
            
        // Helper Utilities
        case 'api/helper/validate-payload':
            if ($requestMethod === 'POST') {
                handleValidatePayload();
            } else {
                sendError(405, 'Method not allowed. Use POST.');
            }
            break;
            
        case 'api/helper/format-payload':
            if ($requestMethod === 'POST') {
                handleFormatPayload();
            } else {
                sendError(405, 'Method not allowed. Use POST.');
            }
            break;
            
        case 'api/helper/generate-group-id':
            if ($requestMethod === 'POST') {
                handleGenerateGroupId();
            } else {
                sendError(405, 'Method not allowed. Use POST.');
            }
            break;
            
        default:
            sendError(404, 'Endpoint not found');
            break;
    }
} catch (Exception $e) {
    sendError(500, 'Internal server error', $e->getMessage());
}

// Queue Management Functions
function handlePublishAuditEvent() {
    global $rtComponents;
    
    $input = getJsonInput();
    if (!$input) return;
    
    $queueName = $input['queue_name'] ?? 'audit-log-queue.fifo';
    $messageId = $rtComponents->publish('', $queueName, $input);
    
    if ($messageId) {
        sendResponse(201, [
            'message' => 'Audit event published successfully',
            'message_id' => $messageId,
            'queue_name' => $queueName
        ]);
    } else {
        sendError(500, 'Failed to publish audit event');
    }
}

/**
 * Handle batch publishing of multiple audit events
 * Processes messages in bulk and returns summary of results
 */
function handlePublishBatchAuditEvents() {
    global $rtComponents;
    
    $input = getJsonInput();
    if (!$input) return;
    
    // Validate required fields
    if (!isset($input['messages']) || !is_array($input['messages'])) {
        sendError(400, 'Invalid request. Missing or invalid "messages" array.');
        return;
    }
    
    $queueName = $input['queue_name'] ?? 'audit-log-queue.fifo';
    $saveToDb = (bool)($input['save_to_db'] ?? true);
    $autoDelete = (bool)($input['auto_delete'] ?? true);
    
    $results = [
        'total_messages' => count($input['messages']),
        'success_count' => 0,
        'failed_count' => 0,
        'message_ids' => [],
        'errors' => []
    ];
    
    // Process each message in the batch
    foreach ($input['messages'] as $index => $message) {
        try {
            if (!is_array($message)) {
                throw new \Exception('Invalid message format. Expected array.');
            }
            
            // Ensure required fields are present
            $requiredFields = ['MessageBody', 'MessageGroupId', 'MessageDeduplicationId'];
            foreach ($requiredFields as $field) {
                if (!isset($message[$field])) {
                    throw new \Exception("Missing required field: {$field}");
                }
            }
            
            // Publish the message
            $messageId = $rtComponents->publish('', $queueName, $message);
            
            if ($messageId) {
                $results['success_count']++;
                $results['message_ids'][] = $messageId;
                
                // If save_to_db is true, save the message to the database
                if ($saveToDb) {
                    $messageBody = json_decode($message['MessageBody'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($messageBody)) {
                        // Handle different message formats
                        if (isset($messageBody['records']) && is_array($messageBody['records'])) {
                            // New format - process records array directly
                            foreach ($messageBody['records'] as $record) {
                                try {
                                    $rtComponents->saveAuditLog($record);
                                } catch (\Exception $e) {
                                    error_log("Error saving audit log (new format): " . $e->getMessage());
                                    continue;
                                }
                            }
                        } elseif (isset($messageBody['NewValue']) && is_array($messageBody['NewValue'])) {
                            // Old format - process records from NewValue
                            foreach ($messageBody['NewValue'] as $record) {
                                try {
                                    $rtComponents->saveAuditLog($record);
                                } catch (\Exception $e) {
                                    error_log("Error saving audit log (old format): " . $e->getMessage());
                                    continue;
                                }
                            }
                        } else {
                            // Handle single record
                            $rtComponents->saveAuditLog($messageBody);
                        }
                    }
                }
            } else {
                throw new \Exception('Failed to publish message');
            }
            
        } catch (\Exception $e) {
            $results['failed_count']++;
            $results['errors'][$index] = [
                'error' => $e->getMessage(),
                'message' => $message
            ];
            
            // Log the error
            error_log(sprintf(
                'Failed to process message %d: %s',
                $index,
                $e->getMessage()
            ));
        }
    }
    
    // Determine response status code based on results
    $statusCode = 200;
    if ($results['failed_count'] > 0) {
        $statusCode = $results['success_count'] > 0 ? 207 : 400; // 207 for partial success
    }
    
    // Clean up response if no errors
    if (empty($results['errors'])) {
        unset($results['errors']);
    }
    
    sendResponse($statusCode, [
        'message' => 'Batch processing completed',
        'results' => $results
    ]);
}

function handleConsumeMessages() {
    global $rtComponents;
    
    $queueName = $_GET['queue_name'] ?? 'audit-log-queue.fifo';
    $maxMessages = (int)($_GET['max_messages'] ?? 10);
    
    $messages = $rtComponents->consume('', $queueName);
    
    sendResponse(200, [
        'queue_name' => $queueName,
        'message_count' => count($messages),
        'messages' => $messages
    ]);
}

function handleQueueStats() {
    global $rtComponents;
    
    $queueManager = $rtComponents->getQueueManager();
    $stats = $queueManager->getQueueStats();
    
    sendResponse(200, [
        'queue_statistics' => $stats
    ]);
}

// Audit Trail Management Functions
function handleProcessAuditDirect() {
    global $rtComponents;
    
    $input = getJsonInput();
    if (!$input) return;
    
    $auditTrailManager = $rtComponents->getAuditTrailManager();
    $auditId = $auditTrailManager->processAuditEventDirect($input);
    
    if ($auditId) {
        sendResponse(201, [
            'message' => 'Audit event processed successfully',
            'audit_id' => $auditId
        ]);
    } else {
        sendError(500, 'Failed to process audit event');
    }
}

function handleGetAuditTrail() {
    global $rtComponents;
    
    $tableName = $_GET['table_name'] ?? '';
    $primaryKeyValue = $_GET['primary_key_value'] ?? '';
    $limit = (int)($_GET['limit'] ?? 50);
    
    if (!$tableName || !$primaryKeyValue) {
        sendError(400, 'table_name and primary_key_value are required');
        return;
    }
    
    $auditTrailManager = $rtComponents->getAuditTrailManager();
    $auditTrail = $auditTrailManager->getAuditTrail($tableName, $primaryKeyValue, $limit);
    
    sendResponse(200, [
        'table_name' => $tableName,
        'primary_key_value' => $primaryKeyValue,
        'audit_count' => count($auditTrail),
        'audit_trail' => $auditTrail
    ]);
}

function handleGetAuditTrailByDateRange() {
    global $rtComponents;
    
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';
    $tableName = $_GET['table_name'] ?? null;
    
    if (!$startDate || !$endDate) {
        sendError(400, 'start_date and end_date are required');
        return;
    }
    
    $auditTrailManager = $rtComponents->getAuditTrailManager();
    $auditTrail = $auditTrailManager->getAuditTrailByDateRange($startDate, $endDate, $tableName);
    
    sendResponse(200, [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'table_name' => $tableName,
        'audit_count' => count($auditTrail),
        'audit_trail' => $auditTrail
    ]);
}

function handleCleanupAuditLogs() {
    global $rtComponents;
    
    $daysToKeep = (int)($_GET['days_to_keep'] ?? 90);
    
    $auditTrailManager = $rtComponents->getAuditTrailManager();
    $deletedCount = $auditTrailManager->cleanupOldAuditLogs($daysToKeep);
    
    sendResponse(200, [
        'message' => 'Audit logs cleanup completed',
        'days_kept' => $daysToKeep,
        'deleted_count' => $deletedCount
    ]);
}

// Database Service Functions
function handleDbInsert() {
    global $rtComponents;
    
    $input = getJsonInput();
    if (!$input) return;
    
    $table = $input['table'] ?? '';
    $data = $input['data'] ?? [];
    
    if (!$table || empty($data)) {
        sendError(400, 'table and data are required');
        return;
    }
    
    $dbService = $rtComponents->getDbService();
    $insertId = $dbService->insert($table, $data);
    
    if ($insertId) {
        sendResponse(201, [
            'message' => 'Record inserted successfully',
            'insert_id' => $insertId,
            'table' => $table
        ]);
    } else {
        sendError(500, 'Failed to insert record');
    }
}

function handleDbUpdate() {
    global $rtComponents;
    
    $input = getJsonInput();
    if (!$input) return;
    
    $table = $input['table'] ?? '';
    $data = $input['data'] ?? [];
    $where = $input['where'] ?? '';
    $whereParams = $input['where_params'] ?? [];
    
    if (!$table || empty($data) || !$where) {
        sendError(400, 'table, data, and where are required');
        return;
    }
    
    $dbService = $rtComponents->getDbService();
    $result = $dbService->update($table, $data, $where, $whereParams);
    
    if ($result) {
        sendResponse(200, [
            'message' => 'Record updated successfully',
            'table' => $table
        ]);
    } else {
        sendError(500, 'Failed to update record');
    }
}

function handleDbSelect() {
    global $rtComponents;
    
    $input = getJsonInput();
    if (!$input) return;
    
    $sql = $input['sql'] ?? '';
    $params = $input['params'] ?? [];
    
    if (!$sql) {
        sendError(400, 'sql is required');
        return;
    }
    
    $dbService = $rtComponents->getDbService();
    $results = $dbService->select($sql, $params);
    
    sendResponse(200, [
        'record_count' => count($results),
        'records' => $results
    ]);
}

function handleDbDelete() {
    global $rtComponents;
    
    $input = getJsonInput();
    if (!$input) return;
    
    $table = $input['table'] ?? '';
    $where = $input['where'] ?? '';
    $whereParams = $input['where_params'] ?? [];
    
    if (!$table || !$where) {
        sendError(400, 'table and where are required');
        return;
    }
    
    $dbService = $rtComponents->getDbService();
    $result = $dbService->delete($table, $where, $whereParams);
    
    if ($result) {
        sendResponse(200, [
            'message' => 'Record deleted successfully',
            'table' => $table
        ]);
    } else {
        sendError(500, 'Failed to delete record');
    }
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

// Helper Utility Functions
function handleValidatePayload() {
    global $rtComponents;
    
    $input = getJsonInput();
    if (!$input) return;
    
    $payload = $input['payload'] ?? [];
    
    $queueHelper = $rtComponents->getQueueHelper();
    $isValid = $queueHelper->validatePayload($payload);
    
    sendResponse(200, [
        'valid' => $isValid,
        'payload' => $payload
    ]);
}

function handleFormatPayload() {
    global $rtComponents;
    
    $input = getJsonInput();
    if (!$input) return;
    
    $data = $input['data'] ?? [];
    
    $queueHelper = $rtComponents->getQueueHelper();
    $formattedPayload = $queueHelper->formatAuditLogPayload($data);
    
    sendResponse(200, [
        'formatted_payload' => $formattedPayload
    ]);
}

function handleGenerateGroupId() {
    global $rtComponents;
    
    $input = getJsonInput();
    if (!$input) return;
    
    $tableName = $input['table_name'] ?? '';
    
    if (!$tableName) {
        sendError(400, 'table_name is required');
        return;
    }
    
    $queueHelper = $rtComponents->getQueueHelper();
    $groupId = $queueHelper->generateMessageGroupId($tableName);
    
    sendResponse(200, [
        'table_name' => $tableName,
        'message_group_id' => $groupId
    ]);
}

// Utility Functions
function getJsonInput() {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError(400, 'Invalid JSON format');
        return null;
    }
    
    return $data;
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
