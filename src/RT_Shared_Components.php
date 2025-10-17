<?php

namespace RT\SharedComponents;

use RT\SharedComponents\Services\QueueManager;
use RT\SharedComponents\Services\AuditTrailManager;
use RT\SharedComponents\Models\AuditLogModel;
use RT\SharedComponents\Services\DbService;
use RT\SharedComponents\Helpers\QueueHelper;
use RT\SharedComponents\Services\RedisService;
use RT\SharedComponents\Services\DatabaseConfigService;

/**
 * RT_Shared_Components - Main entry point for shared components
 * Provides centralized access to queue management, audit trail, and database services
 */
class RT_Shared_Components
{
    private $queueManager;
    private $auditTrailManager;
    private $auditLogModel;
    private $dbService;
    private $queueHelper;
    private $config;
    private $redisService;
    private $dbConfigService;
    
    public function __construct($config)
    {
        $this->config = $config;
        $this->initializeComponents();
    }
    
    /**
     * Initialize all shared components
     */
    private function initializeComponents()
    {
        // Initialize helper first
        $this->queueHelper = new QueueHelper();
        
        // Initialize database service
        if (isset($this->config['database'])) {
            $this->dbService = new DbService($this->config['database']);
        }
        
        // Initialize audit log model with client_id
        $clientId = is_array($this->config['database']) 
            ? ($this->config['database']['client_id'] ?? 'document_service')
            : 'document_service';
        $this->auditLogModel = new AuditLogModel((string)$clientId);
        
        // Initialize queue manager
        $this->queueManager = new QueueManager($this->config);
        
        // Initialize audit trail manager (will be a service, subscribe to auditLogQueue)
        $this->auditTrailManager = new AuditTrailManager($this->config);
        
        // Initialize Redis service if configured
        if (isset($this->config['redis'])) {
            $this->redisService = new RedisService($this->config['redis']);
            
            // Initialize Database Config Service if Redis is available
            $this->dbConfigService = new DatabaseConfigService(
                $this->redisService,
                $this->config['db_config'] ?? []
            );
        }
    }
    
    /**
     * Get Queue Manager instance
     */
    public function getQueueManager()
    {
        return $this->queueManager;
    }
    
    /**
     * Get Audit Trail Manager instance
     */
    public function getAuditTrailManager()
    {
        return $this->auditTrailManager;
    }
    
    /**
     * Get Audit Log Model instance
     */
    public function getAuditLogModel()
    {
        return $this->auditLogModel;
    }
    
    /**
     * Get Database Service instance
     */
    public function getDbService()
    {
        return $this->dbService;
    }
    
    /**
     * Get the queue helper instance
     *
     * @return QueueHelper
     */
    public function getQueueHelper()
    {
        return $this->queueHelper;
    }
    
    /**
     * Get the Redis service instance
     *
     * @return RedisService|null
     */
    public function getRedisService()
    {
        return $this->redisService;
    }
    
    /**
     * Get the database configuration service
     *
     * @return DatabaseConfigService|null
     */
    /**
     * Get the database configuration service
     *
     * @return DatabaseConfigService|null
     */
    public function getDatabaseConfigService()
    {
        return $this->dbConfigService;
    }
    
    /**
     * Get a database connection for a client
     *
     * @param string $clientId Client identifier
     * @return PDO
     * @throws \RuntimeException If database config service is not available
     */
    public function getClientDbConnection(string $clientId): PDO
    {
        if (!$this->dbConfigService) {
            throw new \RuntimeException('Database configuration service is not available. Make sure Redis is configured.');
        }
        
        return $this->dbConfigService->getClientConnection($clientId);
    }
    
    /**
     * Close a client's database connection
     *
     * @param string $clientId Client identifier
     */
    public function closeClientDbConnection(string $clientId): void
    {
        if ($this->dbConfigService) {
            $this->dbConfigService->closeClientConnection($clientId);
        }
    }
    
    /**
     * Publish message to queue with specified URL, queue name, and payload
     */
    public function publish($queueURL, $queueName, $payload)
    {
        try {
            // Set queue name if different
            if ($queueName) {
                $this->queueManager->setQueueName($queueName);
            }
            
            // Format payload for audit log
            $formattedPayload = $this->queueHelper->formatAuditLogPayload($payload);
            $messageGroupId = $this->queueHelper->generateMessageGroupId($formattedPayload['table_name'] ?? 'default');
            
            $messageId = $this->queueManager->publishToAuditLogQueue($formattedPayload, $messageGroupId);
            
            if ($messageId) {
                echo "Message published successfully to {$queueName}. MessageId: {$messageId}\n";
                return $messageId;
            }
            
            return false;
            
        } catch (\Exception $e) {
            echo "Error publishing message: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Consume messages from queue with specified URL and queue name
     */
    public function consume($queueURL, $queueName)
    {
        try {
            // Set queue name if different
            if ($queueName) {
                $this->queueManager->setQueueName($queueName);
            }
            
            return $this->queueManager->consumeFromAuditLogQueue();
            
        } catch (\Exception $e) {
            echo "Error consuming messages: " . $e->getMessage() . "\n";
            return [];
        }
    }
    
    /**
     * Start audit trail manager service (subscribe to auditLogQueue)
     */
    public function startAuditTrailService()
    {
        echo "Starting RT Shared Components - Audit Trail Service\n";
        $this->auditTrailManager->subscribeToAuditLogQueue();
    }
    
    /**
     * Get component status
     */
    public function getStatus()
    {
        return [
            'queue_manager' => $this->queueManager ? 'initialized' : 'not_initialized',
            'audit_trail_manager' => $this->auditTrailManager ? 'initialized' : 'not_initialized',
            'audit_log_model' => $this->auditLogModel ? 'initialized' : 'not_initialized',
            'db_service' => $this->dbService ? 'initialized' : 'not_initialized',
            'queue_helper' => $this->queueHelper ? 'initialized' : 'not_initialized',
            'config_loaded' => !empty($this->config)
        ];
    }
}
