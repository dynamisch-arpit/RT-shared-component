<?php

namespace RT\SharedComponents\Services;

use RT\SharedComponents\Models\AuditLogModel;
use RT\SharedComponents\Services\QueueManager;
use RT\SharedComponents\Helpers\QueueHelper;

class AuditTrailManager
{
    private $queueManager;
    private $auditLogModel;
    private $queueHelper;
    
    public function __construct($config)
    {
        $this->queueManager = new QueueManager($config);
        
        // Get client ID from config or use default
        $clientId = is_array($config['database'] ?? null) 
            ? ($config['database']['client_id'] ?? 'document_service')
            : 'document_service';
            
        $this->auditLogModel = new AuditLogModel($clientId);
        $this->queueHelper = new QueueHelper();
    }
    
    /**
     * Subscribe to audit log queue and process messages
     */
    public function subscribeToAuditLogQueue()
    {
        echo "Starting audit trail manager - subscribing to audit log queue...\n";
        
        while (true) {
            try {
                $processedMessages = $this->queueManager->consumeFromAuditLogQueue(10);
                
                if (!empty($processedMessages)) {
                    echo "Processed " . count($processedMessages) . " audit log messages\n";
                }
                
                // Sleep for a short time before next poll
                sleep(5);
                
            } catch (\Exception $e) {
                echo "Error in audit trail manager: " . $e->getMessage() . "\n";
                sleep(10); // Wait longer on error
            }
        }
    }
    
    /**
     * Publish audit log event
     */
    public function publishAuditEvent($eventData)
    {
        try {
            // Validate and format the payload
            if (!$this->queueHelper->validatePayload($eventData)) {
                throw new \Exception("Invalid audit event payload");
            }
            
            $payload = $this->queueHelper->formatAuditLogPayload($eventData);
            $messageGroupId = $this->queueHelper->generateMessageGroupId($payload['table_name']);
            
            $messageId = $this->queueManager->publishToAuditLogQueue($payload, $messageGroupId);
            
            if ($messageId) {
                $this->queueHelper->logOperation('publish_audit_event', 'success', [
                    'message_id' => $messageId,
                    'table_name' => $payload['table_name'],
                    'event_type' => $payload['event_type']
                ]);
                
                return $messageId;
            }
            
            return false;
            
        } catch (\Exception $e) {
            $this->queueHelper->logOperation('publish_audit_event', 'error', [
                'error' => $e->getMessage(),
                'event_data' => $eventData
            ]);
            
            return false;
        }
    }
    
    /**
     * Get audit trail for a specific record
     */
    public function getAuditTrail($tableName, $primaryKeyValue, $limit = 50)
    {
        return $this->auditLogModel->getAuditLogs($tableName, $primaryKeyValue, $limit);
    }
    
    /**
     * Get audit trail by date range
     */
    public function getAuditTrailByDateRange($startDate, $endDate, $tableName = null)
    {
        return $this->auditLogModel->getAuditLogsByDateRange($startDate, $endDate, $tableName);
    }
    
    /**
     * Process single audit event (for immediate processing)
     */
    public function processAuditEventDirect($eventData)
    {
        try {
            if (!$this->queueHelper->validatePayload($eventData)) {
                throw new \Exception("Invalid audit event payload");
            }
            
            $payload = $this->queueHelper->formatAuditLogPayload($eventData);
            $auditId = $this->auditLogModel->saveAuditLog($payload);
            
            if ($auditId) {
                $this->queueHelper->logOperation('process_audit_direct', 'success', [
                    'audit_id' => $auditId,
                    'table_name' => $payload['table_name'],
                    'event_type' => $payload['event_type']
                ]);
                
                return $auditId;
            }
            
            return false;
            
        } catch (\Exception $e) {
            $this->queueHelper->logOperation('process_audit_direct', 'error', [
                'error' => $e->getMessage(),
                'event_data' => $eventData
            ]);
            
            return false;
        }
    }
    
    /**
     * Get queue statistics
     */
    public function getQueueStatistics()
    {
        return $this->queueManager->getQueueStats();
    }
    
    /**
     * Cleanup old audit logs
     */
    public function cleanupOldAuditLogs($daysToKeep = 90)
    {
        return $this->auditLogModel->cleanupOldLogs($daysToKeep);
    }
}
