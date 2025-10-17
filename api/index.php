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

require_once __DIR__ . '/../src/Queue/SqsFifoQueue.php';
require_once __DIR__ . '/../src/Models/AuditLogModel.php';
require_once __DIR__ . '/../src/Services/QueueManager.php';

use RT\SharedComponents\Queue\SqsFifoQueue;
use RT\SharedComponents\Models\AuditLogModel;
use RT\SharedComponents\Services\QueueManager;

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
            
        case 'audit-log':
            if ($requestMethod === 'POST') {
                handleSendAuditLog();
            } else {
                sendError(405, 'Method not allowed. Use POST.');
            }
            break;
            
        case 'publish':
        case 'send':
            if ($requestMethod === 'POST') {
                handlePublishMessage();
            } else {
                sendError(405, 'Method not allowed. Use POST.');
            }
            break;
            
        case 'consume':
            if ($requestMethod === 'GET') {
                handleConsumeMessages();
            } else {
                sendError(405, 'Method not allowed. Use GET.');
            }
            break;
            
        case 'messages':
            if ($requestMethod === 'GET') {
                handleConsumeMessages();
            } else {
                sendError(405, 'Method not allowed. Use GET.');
            }
            break;
            
        case 'queues':
            if ($requestMethod === 'GET') {
                handleListQueues();
            } elseif ($requestMethod === 'POST') {
                handleCreateQueue();
            } else {
                sendError(405, 'Method not allowed. Use GET or POST.');
            }
            break;
            
        case 'auto-poll':
            if ($requestMethod === 'GET') {
                handleAutoPoll();
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

function handleApiInfo() {
    $info = [
        'api' => 'AWS SQS FIFO Queue API',
        'version' => '1.0.0',
        'endpoints' => [
            'GET /api/info' => 'API information',
            'POST /api/audit-log' => 'Send audit log message to queue',
            'POST /api/publish' => 'Publish message with audit fields',
            'GET /api/consume?queue_name={name}' => 'Consume messages from queue',
            'GET /api/messages?queue_name={name}' => 'Consume messages from queue',
            'GET /api/queues' => 'List available queues',
            'POST /api/queues' => 'Create new queue'
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    sendResponse(200, $info);
}

function handlePublishMessage() {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError(400, 'Invalid JSON format');
        return;
    }

    // Validate required fields for audit log
    $requiredFields = [
        'ChangeID', 'Type', 'TableName', 'PrimaryKeyField', 'PrimaryKeyValue',
        'FieldName', 'OldValue', 'NewValue', 'DateChanged', 'UserID', 
        'IPAddress', 'Url', 'ReferringUrl'
    ];
    
    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            $missingFields[] = $field;
        }
    }
    
    if (!empty($missingFields)) {
        sendError(400, 'Missing required fields fff', print_r($data,true));
        return;
    }
    
    // Load configuration and create queue instance
    $config = require __DIR__ . '/../config.php';
    $fifoQueue = new SqsFifoQueue($config);
    
    // Get queue configuration
    $queueName = $data['queue_name'] ?? 'audit-log-queue.fifo';
    if (!str_ends_with($queueName, '.fifo')) {
        $queueName .= '.fifo';
    }
    
    $fifoQueue->setQueueName($queueName);
    
    // Create queue if needed
    $queueUrl = $fifoQueue->getQueueUrl();
    if (!$queueUrl) {
        $queueAttributes = [];
        
        // Accept direct configuration attributes from user input
        if (!empty($data['MessageRetentionPeriod'])) {
            $queueAttributes['MessageRetentionPeriod'] = (string)$data['MessageRetentionPeriod'];
        }
        if (!empty($data['VisibilityTimeout'])) {
            $queueAttributes['VisibilityTimeout'] = (string)$data['VisibilityTimeout'];
        }
        if (!empty($data['ReceiveMessageWaitTimeSeconds'])) {
            $queueAttributes['ReceiveMessageWaitTimeSeconds'] = (string)$data['ReceiveMessageWaitTimeSeconds'];
        }
        
        // Also accept nested queue_config for backward compatibility
        if (isset($data['queue_config'])) {
            $queueConfig = $data['queue_config'];
            if (!empty($queueConfig['MessageRetentionPeriod'])) {
                $queueAttributes['MessageRetentionPeriod'] = (string)$queueConfig['MessageRetentionPeriod'];
            }
            if (!empty($queueConfig['VisibilityTimeout'])) {
                $queueAttributes['VisibilityTimeout'] = (string)$queueConfig['VisibilityTimeout'];
            }
            if (!empty($queueConfig['ReceiveMessageWaitTimeSeconds'])) {
                $queueAttributes['ReceiveMessageWaitTimeSeconds'] = (string)$queueConfig['ReceiveMessageWaitTimeSeconds'];
            }
        }
        
        $queueUrl = $fifoQueue->createQueue($queueAttributes);
        if (!$queueUrl) {
            sendError(500, 'Failed to create queue');
            return;
        }
    }
    
    // Prepare message data with all audit fields
    $messageData = [
        'ChangeID' => $data['ChangeID'],
        'Type' => $data['Type'],
        'TableName' => $data['TableName'],
        'PrimaryKeyField' => $data['PrimaryKeyField'],
        'PrimaryKeyValue' => $data['PrimaryKeyValue'],
        'FieldName' => $data['FieldName'],
        'OldValue' => $data['OldValue'],
        'NewValue' => $data['NewValue'],
        'DateChanged' => $data['DateChanged'],
        'UserID' => $data['UserID'],
        'IPAddress' => $data['IPAddress'],
        'Url' => $data['Url'],
        'ReferringUrl' => $data['ReferringUrl'],
        'PublishedAt' => date('Y-m-d H:i:s'),
        'MessageId' => uniqid('pub_', true)
    ];
    
    $messageBody = json_encode($messageData);
    $messageGroupId = $data['message_group_id'] ?? $data['TableName'] . '-' . $data['UserID'];
    $messageDeduplicationId = 'pub-' . $data['ChangeID'] . '-' . time();
    
    // Publish message
    $result = $fifoQueue->sendMessage($messageBody, $messageGroupId, $messageDeduplicationId);
    
    if ($result) {
        sendResponse(201, [
            'message' => 'Message published successfully',
            'data' => [
                'queue_name' => $queueName,
                'message_group_id' => $messageGroupId,
                'message_deduplication_id' => $messageDeduplicationId,
                'change_id' => $data['ChangeID'],
                'table_name' => $data['TableName'],
                'published_at' => $messageData['PublishedAt']
            ]
        ]);
    } else {
        sendError(500, 'Failed to publish message to queue testttttttt');
    }
}

function handleSendAuditLog() {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError(400, 'Invalid JSON format');
        return;
    }
    
    // Validate required fields
    $requiredFields = [
        'ChangeID', 'Type', 'TableName', 'PrimaryKeyField', 'PrimaryKeyValue',
        'FieldName', 'OldValue', 'NewValue', 'DateChanged', 'UserID', 
        'IPAddress', 'Url', 'ReferringUrl'
    ];
    
    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            $missingFields[] = $field;
        }
    }
    
    if (!empty($missingFields)) {
        sendError(400, 'Missing required fields gggg', $missingFields);
        return;
    }
    
    // Load configuration and create queue instance
    $config = require __DIR__ . '/../config.php';
    $fifoQueue = new SqsFifoQueue($config);
    
    // Get queue configuration
    $queueName = $data['queue_name'] ?? 'audit-log-queue.fifo';
    if (!str_ends_with($queueName, '.fifo')) {
        $queueName .= '.fifo';
    }
    
    $fifoQueue->setQueueName($queueName);
    
    // Create queue if needed
    $queueUrl = $fifoQueue->getQueueUrl();
    if (!$queueUrl) {
        $queueAttributes = [];
        if (isset($data['queue_config'])) {
            $queueConfig = $data['queue_config'];
            if (!empty($queueConfig['MessageRetentionPeriod'])) {
                $queueAttributes['MessageRetentionPeriod'] = (string)$queueConfig['MessageRetentionPeriod'];
            }
            if (!empty($queueConfig['VisibilityTimeout'])) {
                $queueAttributes['VisibilityTimeout'] = (string)$queueConfig['VisibilityTimeout'];
            }
            if (!empty($queueConfig['ReceiveMessageWaitTimeSeconds'])) {
                $queueAttributes['ReceiveMessageWaitTimeSeconds'] = (string)$queueConfig['ReceiveMessageWaitTimeSeconds'];
            }
        }
        
        $queueUrl = $fifoQueue->createQueue($queueAttributes);
        if (!$queueUrl) {
            sendError(500, 'Failed to create queue');
            return;
        }
    }
    
    // Prepare message data
    $messageData = [
        'ChangeID' => $data['ChangeID'],
        'Type' => $data['Type'],
        'TableName' => $data['TableName'],
        'PrimaryKeyField' => $data['PrimaryKeyField'],
        'PrimaryKeyValue' => $data['PrimaryKeyValue'],
        'FieldName' => $data['FieldName'],
        'OldValue' => $data['OldValue'],
        'NewValue' => $data['NewValue'],
        'DateChanged' => $data['DateChanged'],
        'UserID' => $data['UserID'],
        'IPAddress' => $data['IPAddress'],
        'Url' => $data['Url'],
        'ReferringUrl' => $data['ReferringUrl'],
        'ProcessedAt' => date('Y-m-d H:i:s'),
        'MessageId' => uniqid('msg_', true)
    ];
    
    $messageBody = json_encode($messageData);
    $messageGroupId = $data['message_group_id'] ?? $data['TableName'] . '-' . $data['UserID'];
    $messageDeduplicationId = 'change-' . $data['ChangeID'] . '-' . time();
    
    // Send message
    $result = $fifoQueue->sendMessage($messageBody, $messageGroupId, $messageDeduplicationId);
    
    if ($result) {
        sendResponse(201, [
            'message' => 'Audit log message sent successfully',
            'data' => [
                'queue_name' => $queueName,
                'message_group_id' => $messageGroupId,
                'message_deduplication_id' => $messageDeduplicationId,
                'change_id' => $data['ChangeID'],
                'table_name' => $data['TableName'],
                'processed_at' => $messageData['ProcessedAt']
            ]
        ]);
    } else {
        sendError(500, 'Failed to send message to queue');
    }
}

function handleConsumeMessages() {
    // Increase execution time limit for long polling
    set_time_limit(0);
    ini_set('max_execution_time', 0);
    
    $queueName = $_GET['queue_name'] ?? 'audit-log-queue';
    $maxMessages = min((int)($_GET['max_messages'] ?? 10), 10);
    $waitTime = min((int)($_GET['wait_time'] ?? 20), 20);
    $autoDelete = filter_var($_GET['auto_delete'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
    $saveToDb = filter_var($_GET['save_to_db'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
    $pollMode = $_GET['poll_mode'] ?? 'single'; // single, continuous, long
    
    if (!$queueName) {
        sendError(400, 'queue_name parameter is required');
        return;
    }
    
    if (!str_ends_with($queueName, '.fifo')) {
        $queueName .= '.fifo';
    }
    
    $config = require __DIR__ . '/../config.php';
    $fifoQueue = new SqsFifoQueue($config);
    $fifoQueue->setQueueName($queueName);
    
    $queueUrl = $fifoQueue->getQueueUrl();
    if (!$queueUrl) {
        sendError(404, 'Queue not found', $queueName);
        return;
    }
    
    // Handle different polling modes
    if ($pollMode === 'continuous') {
        handleContinuousPolling($fifoQueue, $queueName, $maxMessages, $waitTime, $autoDelete, $saveToDb);
        return;
    } elseif ($pollMode === 'long') {
        $waitTime = min((int)($_GET['wait_time'] ?? 20), 20); // Long polling up to 20 seconds
    }
    
    $messages = $fifoQueue->receiveMessages($maxMessages, $waitTime);
    
    if ($messages && count($messages) > 0) {
        $processedMessages = [];
        $savedToDbCount = 0;
        error_log("Received messages: " . count($messages));
        foreach ($messages as $message) {
            $processedMessage = [
                'message_id' => $message['MessageId'],
                'body' => json_decode($message['Body'], true) ?: $message['Body'],
                'group_id' => $message['Attributes']['MessageGroupId'] ?? null,
                'received_at' => date('Y-m-d H:i:s'),
                'receipt_handle' => $message['ReceiptHandle']
            ];
            
            // Save to database if enabled
            if ($saveToDb && isset($processedMessage['body']) && is_array($processedMessage['body'])) {
                $dbSaved = saveAuditToDatabase($processedMessage['body']);
                $processedMessage['saved_to_db'] = $dbSaved;
                if ($dbSaved) {
                    $savedToDbCount++;
                }
            }
            
            if ($autoDelete) {
                $deleteResult = $fifoQueue->deleteMessage($message['ReceiptHandle']);
                $processedMessage['deleted'] = $deleteResult !== false;
            }
            
            $processedMessages[] = $processedMessage;
        }
        
        sendResponse(200, [
            'queue_name' => $queueName,
            'message_count' => count($processedMessages),
            'messages' => $processedMessages,
            'poll_mode' => $pollMode,
            'wait_time' => $waitTime,
            'auto_delete' => $autoDelete,
            'save_to_db' => $saveToDb,
            'saved_to_db_count' => $savedToDbCount
        ]);
    } else {
        sendResponse(200, [
            'queue_name' => $queueName,
            'message_count' => 0,
            'messages' => [],
            'poll_mode' => $pollMode,
            'wait_time' => $waitTime
        ]);
    }
}

function saveAuditToDatabase($auditData) {
    try {
        $config = require __DIR__ . '/../config.php';
        // Use a default client ID or get it from config/audit data
        $clientId = $auditData['client_id'] ?? $config['database']['client_id'] ?? 'default_client';
        $auditLogModel = new AuditLogModel($clientId);
        
        // Check if NewValue is an array of changes
        if (isset($auditData['NewValue']) && is_array($auditData['NewValue']) && isset($auditData['NewValue'][0])) {
            $success = true;
            // Process each change as a separate record
            foreach ($auditData['NewValue'] as $change) {
                $payload = [
                    'event_type' => $change['Type'] ?? ($auditData['Type'] ?? 'U'),
                    'table_name' => $change['TableName'] ?? ($auditData['TableName'] ?? ''),
                    'primary_key_field' => $change['PrimaryKeyField'] ?? ($auditData['PrimaryKeyField'] ?? 'ID'),
                    'primary_key_value' => $change['PrimaryKeyValue'] ?? ($auditData['PrimaryKeyValue'] ?? ''),
                    'field_name' => $change['FieldName'] ?? ($auditData['FieldName'] ?? ''),
                    'old_values' => $change['OldValue'] ?? ($auditData['OldValue'] ?? ''),
                    'new_values' => $change['NewValue'] ?? '',
                    'timestamp' => $change['DateChanged'] ?? ($auditData['DateChanged'] ?? date('Y-m-d H:i:s')),
                    'user_id' => $change['UserID'] ?? ($auditData['UserID'] ?? 0),
                    'ip_address' => $change['IPAddress'] ?? ($auditData['IPAddress'] ?? ''),
                    'url' => $change['Url'] ?? ($auditData['Url'] ?? ''),
                    'referring_url' => $change['ReferringUrl'] ?? ($auditData['ReferringUrl'] ?? ''),
                    'x_reference1' => $change['xReference1'] ?? ($auditData['xReference1'] ?? ''),
                    'x_reference2' => $change['xReference2'] ?? ($auditData['xReference2'] ?? '')
                ];
                
                $result = $auditLogModel->saveAuditLog($payload);
                if (!$result) {
                    $success = false;
                    error_log("Failed to save audit log for change: " . json_encode($change));
                }
            }
            return $success;
        } else {
            // Original single record handling
            $payload = [
                'event_type' => $auditData['Type'] ?? 'U',
                'table_name' => $auditData['TableName'] ?? '',
                'primary_key_field' => $auditData['PrimaryKeyField'] ?? 'ID',
                'primary_key_value' => $auditData['PrimaryKeyValue'] ?? '',
                'field_name' => $auditData['FieldName'] ?? '',
                'old_values' => $auditData['OldValue'] ?? '',
                'new_values' => $auditData['NewValue'] ?? '',
                'timestamp' => $auditData['DateChanged'] ?? date('Y-m-d H:i:s'),
                'user_id' => $auditData['UserID'] ?? 0,
                'ip_address' => $auditData['IPAddress'] ?? '',
                'url' => $auditData['Url'] ?? '',
                'referring_url' => $auditData['ReferringUrl'] ?? '',
                'x_reference1' => $auditData['xReference1'] ?? '',
                'x_reference2' => $auditData['xReference2'] ?? ''
            ];
            
            return $auditLogModel->saveAuditLog($payload) !== false;
        }
        
    } catch (Exception $e) {
        error_log("Failed to save audit to database: " . $e->getMessage());
        return false;
    }
}

function handleContinuousPolling($fifoQueue, $queueName, $maxMessages, $waitTime, $autoDelete, $saveToDb = true) {
    global $log;
    $pollDuration = min((int)($_GET['poll_duration'] ?? 60), 300); // Cap at 5 minutes
    $startTime = time();
    $pollCount = 0;
    $totalMessages = 0;
    
    // Set up for streaming response
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    $log("Starting continuous pollingiiii", [
        'queue' => $queueName,
        'max_messages' => $maxMessages,
        'wait_time' => $waitTime,
        'poll_duration' => $pollDuration
    ]);
    
    // Send initial response
    echo json_encode([
        'success' => true,
        'status' => 200,
        'polling_started' => true,
        'queue_name' => $queueName,
        'poll_duration' => $pollDuration,
        'wait_time' => $waitTime,
        'max_messages' => $maxMessages,
        'auto_delete' => $autoDelete,
        'started_at' => date('Y-m-d H:i:s')
    ]) . "\n";
    flush();
    $log("Starting continuous polling", [
        'queue' => $queueName,
        'max_messages' => $maxMessages,
        'wait_time' => $waitTime,
        'poll_duration' => $pollDuration
    ]);
    
    while ((time() - $startTime) < $pollDuration) {
        $pollCount++;
        $messages = $fifoQueue->receiveMessages($maxMessages, $waitTime);
        
        if ($messages && count($messages) > 0) {
            $processedMessages = [];
            $savedToDbCount = 0;
            $log("Processed message", [
                'message_id' => $messages,
                'saved_to_db' => $dbSaved ?? false,
                'deleted' => $deleteResult ?? false
            ]);
            
            foreach ($messages as $message) {
                $processedMessage = [
                    'message_id' => $message['MessageId'],
                    'body' => json_decode($message['Body'], true) ?: $message['Body'],
                    'group_id' => $message['Attributes']['MessageGroupId'] ?? null,
                    'received_at' => date('Y-m-d H:i:s'),
                    'receipt_handle' => $message['ReceiptHandle'],
                    'poll_number' => $pollCount
                ];
                
                // Save to database if enabled using QueueManager
                if ($saveToDb && isset($processedMessage['body']) && is_array($processedMessage['body'])) {
                    $config = require __DIR__ . '/../config.php';
                    $queueManager = new QueueManager($config);
                    $dbSaved = $queueManager->processAuditLogMessage($processedMessage['body']);
                    $processedMessage['saved_to_db'] = $dbSaved;
                    if ($dbSaved) {
                        $savedToDbCount++;
                    }
                }
                
                if ($autoDelete) {
                    $deleteResult = $fifoQueue->deleteMessage($message['ReceiptHandle']);
                    $processedMessage['deleted'] = $deleteResult !== false;
                }
                
                $processedMessages[] = $processedMessage;
                $totalMessages++;
            }
            
            // Stream batch of messages
            echo json_encode([
                'batch' => true,
                'message_count' => count($processedMessages),
                'messages' => $processedMessages,
                'saved_to_db_count' => $savedToDbCount,
                'poll_interval' => $pollDuration,
                'next_poll_in' => $pollDuration,
                'timestamp' => date('Y-m-d H:i:s')
            ]) . "\n";
            flush();
        }
        
        // Small delay to prevent overwhelming
        usleep(100000); // 0.1 second
    }
    
    // Send final summary
    echo json_encode([
        'polling_completed' => true,
        'total_polls' => $pollCount,
        'total_messages' => $totalMessages,
        'duration_seconds' => time() - $startTime,
        'completed_at' => date('Y-m-d H:i:s')
    ]) . "\n";
    flush();
}

function handleAutoPoll() {
    // Increase execution time limit for long polling
    set_time_limit(0);
    ini_set('max_execution_time', 0);
    
    $queueName = $_GET['queue_name'] ?? 'audit-log-queue';
    $maxMessages = min((int)($_GET['max_messages'] ?? 10), 10);
    $pollInterval = max((int)($_GET['poll_interval'] ?? 60), 10); // Minimum 10 seconds
    $maxDuration = min((int)($_GET['max_duration'] ?? 3600), 7200); // Max 2 hours
    $autoDelete = filter_var($_GET['auto_delete'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
    $saveToDb = filter_var($_GET['save_to_db'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
    $callback = $_GET['callback'] ?? null; // Optional webhook callback
    
    if (!str_ends_with($queueName, '.fifo')) {
        $queueName .= '.fifo';
    }
    
    $config = require __DIR__ . '/../config.php';
    $fifoQueue = new SqsFifoQueue($config);
    $fifoQueue->setQueueName($queueName);
    
    $queueUrl = $fifoQueue->getQueueUrl();
    if (!$queueUrl) {
        sendError(404, 'Queue not found', $queueName);
        return;
    }
    
    $startTime = time();
    $pollCount = 0;
    $totalMessages = 0;
    
    // Set up streaming response
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    
    // Send initial response
    echo json_encode([
        'success' => true,
        'status' => 200,
        'auto_polling_started' => true,
        'queue_name' => $queueName,
        'poll_interval' => $pollInterval,
        'max_duration' => $maxDuration,
        'max_messages' => $maxMessages,
        'auto_delete' => $autoDelete,
        'callback' => $callback,
        'started_at' => date('Y-m-d H:i:s')
    ]) . "\n";
    flush();
    
    while ((time() - $startTime) < $maxDuration) {
        $pollStartTime = time();
        $messages = $fifoQueue->receiveMessages($maxMessages, 20); // Long polling
        
        if ($messages && count($messages) > 0) {
            $processedMessages = [];
            $savedToDbCount = 0;
            
            foreach ($messages as $message) {
                $processedMessage = [
                    'message_id' => $message['MessageId'],
                    'body' => json_decode($message['Body'], true) ?: $message['Body'],
                    'group_id' => $message['Attributes']['MessageGroupId'] ?? null,
                    'received_at' => date('Y-m-d H:i:s'),
                    'receipt_handle' => $message['ReceiptHandle']
                ];
                
                // Save to database if enabled using QueueManager
                if ($saveToDb && isset($processedMessage['body']) && is_array($processedMessage['body'])) {
                    $config = require __DIR__ . '/../config.php';
                    $queueManager = new QueueManager($config);
                    $dbSaved = $queueManager->processAuditLogMessage($processedMessage['body']);
                    $processedMessage['saved_to_db'] = $dbSaved;
                    if ($dbSaved) {
                        $savedToDbCount++;
                    }
                }
                
                if ($autoDelete) {
                    $deleteResult = $fifoQueue->deleteMessage($message['ReceiptHandle']);
                    $processedMessage['deleted'] = $deleteResult !== false;
                }
                
                $processedMessages[] = $processedMessage;
                $totalMessages++;
            }
            
            $batchData = [
                'auto_poll_batch' => true,
                'poll_number' => $pollCount,
                'message_count' => count($processedMessages),
                'messages' => $processedMessages,
                'saved_to_db_count' => $savedToDbCount,
                'timestamp' => date('Y-m-d H:i:s'),
                'next_poll_in' => $pollInterval
            ];
            
            // Stream batch of messages
            echo json_encode($batchData) . "\n";
            flush();
            
            // Send to callback if provided
            if ($callback) {
                sendToCallback($callback, $batchData);
            }
        } else {
            // Send heartbeat even when no messages
            echo json_encode([
                'heartbeat' => true,
                'poll_number' => $pollCount,
                'message_count' => 0,
                'timestamp' => date('Y-m-d H:i:s'),
                'next_poll_in' => $pollInterval
            ]) . "\n";
            flush();
        }
        
        // Calculate sleep time to maintain interval
        $pollDuration = time() - $pollStartTime;
        $sleepTime = max(0, $pollInterval - $pollDuration);
        
        if ($sleepTime > 0 && (time() - $startTime + $sleepTime) < $maxDuration) {
            sleep($sleepTime);
        }
    }
    
    // Send final summary
    echo json_encode([
        'auto_polling_completed' => true,
        'total_polls' => $pollCount,
        'total_messages' => $totalMessages,
        'duration_seconds' => time() - $startTime,
        'completed_at' => date('Y-m-d H:i:s')
    ]) . "\n";
    flush();
}

function sendToCallback($callbackUrl, $data) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $callbackUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Log callback result (optional)
    error_log("Callback to $callbackUrl returned HTTP $httpCode");
}

function handleListQueues() {
    $config = require __DIR__ . '/../config.php';
    $fifoQueue = new SqsFifoQueue($config);
    
    // This would require additional method in SqsFifoQueue class
    sendResponse(200, [
        'message' => 'Queue listing not implemented yet',
        'note' => 'Use specific queue names to check if they exist'
    ]);
}

function handleCreateQueue() {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError(400, 'Invalid JSON format');
        return;
    }
    
    $queueName = $data['queue_name'] ?? null;
    if (!$queueName) {
        sendError(400, 'queue_name is required');
        return;
    }
    
    if (!str_ends_with($queueName, '.fifo')) {
        $queueName .= '.fifo';
    }
    
    $config = require __DIR__ . '/../config.php';
    $fifoQueue = new SqsFifoQueue($config);
    $fifoQueue->setQueueName($queueName);
    
    $queueAttributes = $data['attributes'] ?? [];
    $queueUrl = $fifoQueue->createQueue($queueAttributes);
    
    if ($queueUrl) {
        sendResponse(201, [
            'message' => 'Queue created successfully',
            'queue_name' => $queueName,
            'queue_url' => $queueUrl
        ]);
    } else {
        sendError(500, 'Failed to create queue');
    }
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
