<?php

namespace RT\SharedComponents\Services;

use RT\SharedComponents\Queue\SqsFifoQueue;
use RT\SharedComponents\Models\AuditLogModel;
use RT\SharedComponents\Helpers\QueueHelper;

class QueueManager
{
    private $sqsQueue;
    private $auditLogModel;
    private $queueHelper;
    
    public function __construct($config)
    {
        $this->sqsQueue = new SqsFifoQueue($config);
        
        // Get client ID from config or use default
        $clientId = $config['database']['client_id'] ?? 'document_service';
        $this->auditLogModel = new AuditLogModel($clientId);
        
        $this->queueHelper = new QueueHelper();
    }
    
    /**
     * Publish message to audit log queue
     */
    public function publishToAuditLogQueue($payload, $messageGroupId = 'audit-logs')
    {
        try {
            $messageBody = json_encode($payload);
            $deduplicationId = $this->queueHelper->generateDeduplicationId($payload);
            
            $messageId = $this->sqsQueue->sendMessage(
                $messageBody,
                $messageGroupId,
                $deduplicationId
            );
            
            if ($messageId) {
                echo "Audit log message published successfully. MessageId: {$messageId}\n";
                return $messageId;
            }
            
            return false;
            
        } catch (\Exception $e) {
            echo "Error publishing to audit log queue: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Consume messages from audit log queue
     */
    public function consumeFromAuditLogQueue($maxMessages = 10)
    {
        try {
            $messages = $this->sqsQueue->receiveMessages($maxMessages);
            
            if (empty($messages)) {
                return [];
            }
            
            $processedMessages = [];
            
            foreach ($messages as $message) {
                $payload = json_decode($message['Body'], true);
                
                if ($this->processAuditLogMessage($payload)) {
                    // Delete message after successful processing
                    $this->sqsQueue->deleteMessage($message['ReceiptHandle']);
                    $processedMessages[] = $payload;
                    echo "Processed and deleted audit log message\n";
                } else {
                    echo "Failed to process audit log message\n";
                }
            }
            
            return $processedMessages;
            
        } catch (\Exception $e) {
            echo "Error consuming from audit log queue: " . $e->getMessage() . "\n";
            return [];
        }
    }
    
    /**
     * Process individual audit log message
     */
    public function processAuditLogMessage($payload)
    {
        try {
            // Handle different message formats
            if (isset($payload['records']) && is_array($payload['records'])) {
                // New format - process records array directly
                $success = true;
                foreach ($payload['records'] as $record) {
                    if (!$this->auditLogModel->saveAuditLog($record)) {
                        $success = false;
                    }
                }
                return $success;
            } elseif (isset($payload['NewValue']) && is_array($payload['NewValue'])) {
                // Old format - process records from NewValue
                $success = true;
                foreach ($payload['NewValue'] as $record) {
                    if (!$this->auditLogModel->saveAuditLog($record)) {
                        $success = false;
                    }
                }
                return $success;
            } else {
                // Single record
                return $this->auditLogModel->saveAuditLog($payload);
            }
            
        } catch (\Exception $e) {
            error_log("Error processing audit log message: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get queue statistics
     */
    public function getQueueStats()
    {
        return $this->sqsQueue->getQueueAttributes();
    }
    
    /**
     * Set queue name for different environments
     */
    public function setQueueName($queueName)
    {
        $this->sqsQueue->setQueueName($queueName);
    }
}
