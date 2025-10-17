<?php

namespace RT\SharedComponents\Helpers;

class QueueHelper
{
    /**
     * Generate deduplication ID for SQS FIFO queue
     */
    public function generateDeduplicationId($payload)
    {
        // Create unique ID based on payload content
        $content = is_array($payload) ? json_encode($payload) : $payload;
        return hash('sha256', $content . microtime(true));
    }
    
    /**
     * Format audit log payload
     */
    public function formatAuditLogPayload($data)
    {
        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'event_type' => $data['event_type'] ?? 'unknown',
            'table_name' => $data['table_name'] ?? '',
            'primary_key_value' => $data['primary_key_value'] ?? '',
            'user_id' => $data['user_id'] ?? 0,
            'ip_address' => $data['ip_address'] ?? '',
            'url' => $data['url'] ?? '',
            'referring_url' => $data['referring_url'] ?? '',
            'old_values' => $data['old_values'] ?? [],
            'new_values' => $data['new_values'] ?? [],
            'x_reference1' => $data['x_reference1'] ?? '',
            'x_reference2' => $data['x_reference2'] ?? ''
        ];
    }
    
    /**
     * Validate queue message payload
     */
    public function validatePayload($payload)
    {
        if (!is_array($payload)) {
            return false;
        }
        
        $requiredFields = ['event_type', 'table_name'];
        
        foreach ($requiredFields as $field) {
            if (!isset($payload[$field]) || empty($payload[$field])) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Generate message group ID based on table name
     */
    public function generateMessageGroupId($tableName)
    {
        return 'audit-' . strtolower($tableName);
    }
    
    /**
     * Create retry configuration for failed messages
     */
    public function createRetryConfig($maxRetries = 3, $delaySeconds = 30)
    {
        return [
            'maxRetries' => $maxRetries,
            'delaySeconds' => $delaySeconds,
            'backoffMultiplier' => 2
        ];
    }
    
    /**
     * Log queue operation
     */
    public function logOperation($operation, $status, $details = [])
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'operation' => $operation,
            'status' => $status,
            'details' => $details
        ];
        
        // Log to file or database
        error_log("Queue Operation: " . json_encode($logEntry));
        
        return $logEntry;
    }
}
