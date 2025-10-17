<?php

namespace RT\SharedComponents\Models;

use PDO;
use PDOException;
use RT\SharedComponents\Services\DbConnectionManager;
use RT\SharedComponents\Exceptions\DatabaseConfigException;

// Conditionally use Laravel's Log facade if available
if (class_exists('\\Illuminate\\Support\\Facades\\Log')) {
    class_alias('\\Illuminate\\Support\\Facades\\Log', 'RT\\SharedComponents\\Models\\LogFacade');
}

/**
 * Simple logger class that falls back to error_log if Laravel's logger is not available
 */
class Logger
{
    public static function log($level, $message, $context = [])
    {
        if (class_exists('RT\\SharedComponents\\Models\\LogFacade')) {
            // Use Laravel's logger if available
            LogFacade::{$level}($message, $context);
        } else {
            // Fallback to error_log
            $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
            error_log(sprintf('[%s] %s%s', strtoupper($level), $message, $contextStr));
        }
    }
}

class AuditLogModel
{
    private $pdo;
    private $tableName = 'auditGeneric';
    private $clientId;
    
    /**
     * Constructor
     * 
     * @param string $clientId The client identifier for database connection
     * @param PDO|null $pdo Optional PDO connection (for testing or dependency injection)
     */
    public function __construct(string $clientId, ?PDO $pdo = null)
    {
        $this->clientId = $clientId;
        $this->pdo = $pdo;
    }
    
    /**
     * Get the PDO connection for the client
     * 
     * @return PDO
     * @throws \RuntimeException If database connection cannot be established
     */
    private function getConnection(): PDO
    {
        if ($this->pdo === null) {
            try {
                $this->pdo = DbConnectionManager::getClientConnection($this->clientId);
            } catch (DatabaseConfigException $e) {
                throw new \RuntimeException(
                    "Failed to get database connection for client {$this->clientId}: " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
        
        return $this->pdo;
    }
    
    /**
     * Set the table name for audit logs
     * 
     * @param string $tableName
     * @return self
     */
    public function setTableName(string $tableName): self
    {
        $this->tableName = $tableName;
        return $this;
    }
    
    /**
     * Test the database connection and return the database name if successful
     * 
     * @return string|null The database name if connection is successful, null otherwise
     */
    public function testConnection(): ?string
    {
        try {
            $pdo = $this->getConnection();
            $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
            return $dbName ?: null;
        } catch (\Exception $e) {
            // Log directly to error_log to avoid dependency on Logger class
            error_log(sprintf(
                'Database connection test failed for client %s: %s',
                $this->clientId,
                $e->getMessage()
            ));
            throw $e; // Re-throw to let the caller handle it
        }
    }
    
    /**
     * Save audit log entry to database
     * 
     * @param array $payload The audit log data
     * @return int|false The ID of the inserted record or false on failure
     * @throws \RuntimeException If there's an error saving the audit log
     */
    public function saveAuditLog(array $payload)
    {
        // Log client and payload information
        Logger::log('info', 'Attempting to save audit log', [
            'client' => $this->clientId,
            'type' => $payload['Type'] ?? 'N/A',
            'table' => $payload['TableName'] ?? 'N/A',
            'primary_key' => $payload['PrimaryKeyValue'] ?? 'N/A'
        ]);
        
        try {
            // Get and log database connection details
            $pdo = $this->getConnection();
            
            // Log database connection details
            $dsn = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $host = $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
            $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
            
            Logger::log('debug', 'Database connection established', [
                'dsn' => $dsn,
                'host' => $host,
                'database' => $dbName
            ]);
            
            // Start transaction for atomicity
            Logger::log('debug', 'Starting database transaction');
            $pdo->beginTransaction();
            
            // Ensure the audit table exists for this client
            Logger::log('debug', 'Ensuring table exists', ['table' => $this->tableName]);
            $this->ensureTableExists($pdo);
            
            $sql = "INSERT INTO `{$this->tableName}` (
                `Type`, `TableName`, `PrimaryKeyField`, `PrimaryKeyValue`, 
                `FieldName`, `OldValue`, `NewValue`, `DateChanged`, 
                `UserID`, `IPAddress`, `Url`, `ReferringUrl`, 
                `xReference1`, `xReference2`
            ) VALUES (
                :Type, :TableName, :PrimaryKeyField, :PrimaryKeyValue,
                :FieldName, :OldValue, :NewValue, :DateChanged,
                :UserID, :IPAddress, :Url, :ReferringUrl,
                :xReference1, :xReference2
            )";
            
            // Log the SQL query (without parameters for security)
            Logger::log('debug', 'Preparing SQL', ['sql' => preg_replace('/\s+/', ' ', trim($sql))]);
            
            $stmt = $pdo->prepare($sql);
            
            // Current timestamp for created_at
            $now = date('Y-m-d H:i:s');
            
            // Log parameter binding
            Logger::log('debug', 'Binding parameters to prepared statement');
            
            // Bind parameters with proper null handling
            $this->bindAuditLogParams($stmt, $payload, $now);
            
            // Execute the query
            Logger::log('debug', 'Executing prepared statement');
            $stmt->execute();
            
            $lastInsertId = $pdo->lastInsertId();
            
            // Commit the transaction
            Logger::log('debug', 'Committing transaction', ['last_insert_id' => $lastInsertId]);
            $pdo->commit();
            
            Logger::log('info', 'Successfully saved audit log', ['id' => $lastInsertId]);
            
            return $lastInsertId;
            
        } catch (\Exception $e) {
            // Log the full error with stack trace
            Logger::log('error', 'Error saving audit log', [
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Rollback the transaction in case of error
            if (isset($pdo) && $pdo->inTransaction()) {
                Logger::log('warning', 'Rolling back transaction due to error');
                $pdo->rollBack();
            }
            
            throw new \RuntimeException("Failed to save audit log: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Bind parameters for the audit log insert statement
     * 
     * @param \PDOStatement $stmt
     * @param array $payload
     * @param string $timestamp
     */
    private function bindAuditLogParams(\PDOStatement $stmt, array $payload, string $timestamp): void
    {
        // Handle JSON encoding for OldValue and NewValue if they are arrays or objects
        $oldValue = $this->prepareValue($payload['OldValue'] ?? null);
        $newValue = $this->prepareValue($payload['NewValue'] ?? null);
        
        // Bind all parameters with proper null handling
        $stmt->bindValue(':Type', $payload['Type'] ?? 'U', PDO::PARAM_STR);
        $stmt->bindValue(':TableName', $payload['TableName'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':PrimaryKeyField', $payload['PrimaryKeyField'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':PrimaryKeyValue', $payload['PrimaryKeyValue'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':FieldName', $payload['FieldName'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':OldValue', $oldValue, PDO::PARAM_STR);
        $stmt->bindValue(':NewValue', $newValue, PDO::PARAM_STR);
        $stmt->bindValue(':DateChanged', $payload['DateChanged'] ?? $timestamp, PDO::PARAM_STR);
        $stmt->bindValue(':UserID', $payload['UserID'] ?? 0, PDO::PARAM_INT);
        
        // Get IP and URL from server if not provided
        $ipAddress = $payload['IPAddress'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $url = $payload['Url'] ?? ($_SERVER['REQUEST_URI'] ?? '');
        $referrer = $payload['ReferringUrl'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
        
        $stmt->bindValue(':IPAddress', $ipAddress, PDO::PARAM_STR);
        $stmt->bindValue(':Url', $url, PDO::PARAM_STR);
        $stmt->bindValue(':ReferringUrl', $referrer, PDO::PARAM_STR);
        
        // Optional xReference fields
        $stmt->bindValue(':xReference1', $payload['xReference1'] ?? 'eDocs', PDO::PARAM_STR);
        $stmt->bindValue(':xReference2', $payload['xReference2'] ?? 'eDocs', PDO::PARAM_STR);
        // Message deduplication ID for FIFO queues
        $dedupeId = $payload['message_deduplication_id'] ?? 
                   ($payload['ChangeID'] ?? md5(serialize($payload) . microtime(true)));
    }
    
    /**
     * Prepare a value for storage in the audit log
     * 
     * @param mixed $value
     * @return string|null
     */
    private function prepareValue($value): ?string
    {
        if ($value === null) {
            return null;
        }
        
        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return (string)$value;
        }
        
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    
    /**
     * Ensure the audit log table exists in the database
     * 
     * @param PDO $pdo
     * @throws \PDOException If table creation fails
     */
    private function ensureTableExists(PDO $pdo): void
    {
        $tableName = $pdo->quote($this->tableName);
        
        // Check if table exists
        $result = $pdo->query("SHOW TABLES LIKE {$tableName}");
        
        if ($result->rowCount() === 0) {
            // Table doesn't exist, create it
            $this->createAuditTable($pdo);
        }
    }
    
    /**
     * Create the audit log table
     * 
     * @param PDO $pdo
     * @throws \PDOException If table creation fails
     */
    private function createAuditTable(PDO $pdo): void
    {
        $sql = "
        CREATE TABLE `{$this->tableName}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `Type` CHAR(1) NOT NULL COMMENT 'I=Insert, U=Update, D=Delete',
            `TableName` VARCHAR(100) NOT NULL,
            `PrimaryKeyField` VARCHAR(100) NOT NULL,
            `PrimaryKeyValue` VARCHAR(255) NOT NULL,
            `FieldName` VARCHAR(100) DEFAULT NULL,
            `OldValue` TEXT DEFAULT NULL,
            `NewValue` TEXT DEFAULT NULL,
            `DateChanged` DATETIME NOT NULL,
            `UserID` INT UNSIGNED NOT NULL DEFAULT 0,
            `IPAddress` VARCHAR(45) DEFAULT NULL,
            `Url` VARCHAR(512) DEFAULT NULL,
            `ReferringUrl` VARCHAR(512) DEFAULT NULL,
            `xReference1` VARCHAR(255) DEFAULT NULL,
            `xReference2` VARCHAR(255) DEFAULT NULL
            PRIMARY KEY (`id`),
            KEY `idx_table_pk` (`TableName`, `PrimaryKeyField`, `PrimaryKeyValue`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_user` (`UserID`),
            KEY `idx_dedupe` (`message_deduplication_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit log for tracking data changes';
        ";
        
        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                "Failed to create audit log table: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
}

/**
 * Get a single audit log entry by ID
 * 
 * @param int $id The audit log ID
 * @return array|null The audit log record or null if not found
 * @throws \RuntimeException If there's an error executing the query
 */
public function getAuditLogById(int $id): ?array
{
    $pdo = $this->getConnection();
    
    try {
        $sql = "SELECT * FROM `{$this->tableName}` WHERE `id` = :id LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: null;
        
    } catch (\PDOException $e) {
        throw new \RuntimeException(
            "Failed to fetch audit log #{$id} for client {$this->clientId}: " . $e->getMessage(),
            (int)$e->getCode(),
            $e
        );
    }
}

/**
 * Get the count of audit logs matching the given filters
 * 
 * @param array $filters Same as getAuditLogs()
 * @return int The count of matching records
 * @throws \RuntimeException If there's an error executing the query
 */
public function getAuditLogsCount(array $filters = []): int
{
    $pdo = $this->getConnection();
    
    try {
        $sql = "SELECT COUNT(*) as count FROM `{$this->tableName}` WHERE 1=1";
        $params = [];
        
        // Apply the same filters as getAuditLogs()
        if (!empty($filters['table_name'])) {
            $sql .= " AND `TableName` = :table_name";
            $params[':table_name'] = $filters['table_name'];
        }
        
        if (!empty($filters['primary_key_value'])) {
            $sql .= " AND `PrimaryKeyValue` = :pk_value";
            $params[':pk_value'] = $filters['primary_key_value'];
        }
        
        if (isset($filters['user_id'])) {
            $sql .= " AND `UserID` = :user_id";
            $params[':user_id'] = (int)$filters['user_id'];
        }
        
        if (!empty($filters['type'])) {
            $sql .= " AND `Type` = :type";
            $params[':type'] = strtoupper(substr($filters['type'], 0, 1));
        }
        
        if (!empty($filters['start_date'])) {
            $sql .= " AND `DateChanged` >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $sql .= " AND `DateChanged` <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }
        
        if (!empty($filters['field_name'])) {
            $sql .= " AND `FieldName` = :field_name";
            $params[':field_name'] = $filters['field_name'];
        }
        
        $stmt = $pdo->prepare($sql);
        
        // Bind all parameters with proper types
        foreach ($params as $key => $value) {
            $paramType = PDO::PARAM_STR;
            
            if ($key === ':user_id') {
                $paramType = PDO::PARAM_INT;
            }
            
            $stmt->bindValue($key, $value, $paramType);
        }
        
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (int)($result['count'] ?? 0);
        
    } catch (\PDOException $e) {
        throw new \RuntimeException(
            "Failed to count audit logs for client {$this->clientId}: " . $e->getMessage(),
            (int)$e->getCode(),
            $e
        );
    }
}

    /**
     * Save multiple audit log entries in a single transaction
     * 
     * @param array $auditLogs Array of audit log payloads
     * @return array Array of inserted IDs in the same order as input
     * @throws \RuntimeException If there's an error saving the audit logs
     */
    public function saveBulkAuditLogs(array $auditLogs): array
    {
        if (empty($auditLogs)) {
            return [];
        }

        Logger::log('info', 'Attempting to save bulk audit logs', [
            'client' => $this->clientId,
            'count' => count($auditLogs)
        ]);

        $pdo = null;
        $insertedIds = [];
        $currentTimestamp = date('Y-m-d H:i:s');

        try {
            $pdo = $this->getConnection();
            
            // Log database connection details
            $dsn = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
            
            Logger::log('debug', 'Database connection established for bulk insert', [
                'dsn' => $dsn,
                'database' => $dbName,
                'table' => $this->tableName
            ]);

            // Start transaction
            $pdo->beginTransaction();
            Logger::log('debug', 'Began database transaction for bulk insert');

            // Ensure the audit table exists
            $this->ensureTableExists($pdo);

            // Prepare the SQL statement once
            $sql = "INSERT INTO `{$this->tableName}` (
                `Type`, `TableName`, `PrimaryKeyField`, `PrimaryKeyValue`, 
                `FieldName`, `OldValue`, `NewValue`, `DateChanged`, 
                `UserID`, `IPAddress`, `Url`, `ReferringUrl`, 
                `xReference1`, `xReference2`
            ) VALUES (
                :Type, :TableName, :PrimaryKeyField, :PrimaryKeyValue,
                :FieldName, :OldValue, :NewValue, :DateChanged,
                :UserID, :IPAddress, :Url, :ReferringUrl,
                :xReference1, :xReference2
            )";

            $stmt = $pdo->prepare($sql);
            
            foreach ($auditLogs as $index => $payload) {
                try {
                    // Prepare values
                    $oldValue = $this->prepareValue($payload['OldValue'] ?? null);
                    $newValue = $this->prepareValue($payload['NewValue'] ?? null);
                    $timestamp = $payload['DateChanged'] ?? $currentTimestamp;
                    
                    // Get IP and URL from server if not provided
                    $ipAddress = $payload['IPAddress'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    $url = $payload['Url'] ?? ($_SERVER['REQUEST_URI'] ?? '');
                    $referrer = $payload['ReferringUrl'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
                    
                    // Bind parameters
                    $params = [
                        ':Type' => $payload['Type'] ?? 'U',
                        ':TableName' => $payload['TableName'] ?? null,
                        ':PrimaryKeyField' => $payload['PrimaryKeyField'] ?? null,
                        ':PrimaryKeyValue' => $payload['PrimaryKeyValue'] ?? null,
                        ':FieldName' => $payload['FieldName'] ?? null,
                        ':OldValue' => $oldValue,
                        ':NewValue' => $newValue,
                        ':DateChanged' => $timestamp,
                        ':UserID' => $payload['UserID'] ?? 0,
                        ':IPAddress' => $ipAddress,
                        ':Url' => $url,
                        ':ReferringUrl' => $referrer,
                        ':xReference1' => $payload['xReference1'] ?? 'eDocs',
                        ':xReference2' => $payload['xReference2'] ?? 'eDocs',
                    ];

                    // Execute the statement
                    $stmt->execute($params);
                    $insertedIds[$index] = $pdo->lastInsertId();

                } catch (\Exception $e) {
                    // Log the error but continue with other records
                    Logger::log('error', 'Error processing audit log entry in bulk insert', [
                        'index' => $index,
                        'error' => $e->getMessage(),
                        'payload' => array_intersect_key($payload, array_flip(['Type', 'TableName', 'PrimaryKeyValue', 'FieldName']))
                    ]);
                    $insertedIds[$index] = false;
                }
            }

            // Commit the transaction
            $pdo->commit();
            Logger::log('info', 'Successfully committed bulk insert transaction', [
                'success_count' => count(array_filter($insertedIds)),
                'total_count' => count($auditLogs)
            ]);

            return $insertedIds;

        } catch (\Exception $e) {
            // Rollback the transaction in case of error
            if ($pdo && $pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                    Logger::log('warning', 'Rolled back bulk insert transaction due to error');
                } catch (\Exception $rollbackEx) {
                    Logger::log('error', 'Failed to rollback transaction', [
                        'error' => $rollbackEx->getMessage()
                    ]);
                }
            }

            Logger::log('error', 'Failed to save bulk audit logs', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);

            throw new \RuntimeException(
                "Failed to save bulk audit logs: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

/**
 * Get audit logs by date range
 * 
 * @param string $startDate Start date (Y-m-d H:i:s)
 * @param string $endDate End date (Y-m-d H:i:s)
 * @param string|null $tableName Optional table name filter
 * @return array Array of audit log records
 * @throws \RuntimeException If there's an error executing the query
 */
public function getAuditLogsByDateRange(string $startDate, string $endDate, string $tableName = null): array
{
        $pdo = $this->getConnection();
        
        try {
            $sql = "SELECT * FROM `{$this->tableName}` 
                WHERE `DateChanged` BETWEEN :start_date AND :end_date";
            
            $params = [
                ':start_date' => $startDate,
                ':end_date' => $endDate
            ];
            
            if ($tableName) {
                $sql .= " AND `TableName` = :table_name";
                $params[':table_name'] = $tableName;
            }
            
            $sql .= " ORDER BY `DateChanged` DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                "Failed to fetch audit logs by date range for client {$this->clientId}: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        
        $params = [
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ];
        
        if ($tableName) {
            $sql .= " AND `TableName` = :table_name";
            $params[':table_name'] = $tableName;
        }
        
        $sql .= " ORDER BY `DateChanged` DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (\PDOException $e) {
        throw new \RuntimeException(
            "Failed to fetch audit logs by date range for client {$this->clientId}: " . $e->getMessage(),
            (int)$e->getCode(),
            $e
        );
    }
}
    
    /**
     * Delete old audit logs (cleanup)
     */
    public function cleanupOldLogs($daysToKeep = 90)
    {
        try {
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysToKeep} days"));
            
            $sql = "DELETE FROM {$this->tableName} WHERE DateChanged < :cutoff_date";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':cutoff_date', $cutoffDate);
            
            $result = $stmt->execute();
            $deletedRows = $stmt->rowCount();
            
            echo "Cleaned up {$deletedRows} old audit log entries\n";
            return $deletedRows;
            
        } catch (PDOException $e) {
            echo "Error cleaning up old audit logs: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function createClientAuditLogModel($auditData, $clientName) {
        try {
            // Get client-specific database config first
            $clientConfig = $this->getClientDatabaseConfig($clientName);
            
            if ($clientConfig === null) {
                // $this->logMessage("No database configuration found for client: " . $clientName, 'WARN');
                echo "No database configuration found for client: " . $clientName . "\n";
                return null;
            }
            
            try {
                // Create a new PDO connection
                $dsn = "mysql:host={$clientConfig['host']};dbname={$clientConfig['database']};charset=utf8mb4";
                $pdo = new \PDO(
                    $dsn,
                    $clientConfig['username'],
                    $clientConfig['password'],
                    [
                        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                        \PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
                
                // Create the AuditLogModel with the PDO connection
                $auditLogModel = new \RT\SharedComponents\Models\AuditLogModel($clientName, $pdo);
                // $this->logMessage("Created AuditLogModel with client-specific database: " . $clientConfig['database'], 'DEBUG');
                echo "Created AuditLogModel with client-specific database: " . $clientConfig['database'] . "\n";
                
                return $auditLogModel;
                
            } catch (\PDOException $e) {
                // $this->logMessage("Failed to connect to client database: " . $e->getMessage(), 'ERROR');
                echo "Failed to connect to client database: " . $e->getMessage() . "\n";
                return null;
            }
            
        } catch (\Exception $e) {
            // $this->logMessage("Error creating AuditLogModel for client $clientName: " . $e->getMessage(), 'ERROR');
            echo "Error creating AuditLogModel for client $clientName: " . $e->getMessage() . "\n";
            return null;
        }
    }

    private function getClientDatabaseConfig($clientName) {
        static $pdo = null;
        
        try {
            if ($pdo === null) {
                $dbHost = '127.0.0.1';
                $dbPort = '3306';
                $dbUser = getenv(DB_USERNAME) ?: '';
                $dbPass = getenv(DB_PASSWORD) ?: '';
                $dbName = getenv(DB_DATABASE) ?: '';

                echo "Attempting to connect to database. Host: {$dbHost}, Port: {$dbPort}, DB: {$dbName}, User: " . ($dbUser ? '***' : 'NULL') . "\n";
                
                $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName}";
                $pdo = new PDO(
                    $dsn,
                    $dbUser,
                    $dbPass,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                    ]
                );
                echo "Successfully connected to $dbName database\n";
            }
            
            // Query client configuration
            $query = "
                SELECT db_host, db_name, db_username, db_password 
                FROM client_configs 
                WHERE client_name = :client_name AND is_active = 1
                LIMIT 1
            ";

            echo "Querying client configuration for client: {$clientName}\n";
            $stmt = $pdo->prepare($query);
            $stmt->execute([':client_name' => $clientName]);
            $config = $stmt->fetch();
            
            if (!$config) {
                echo "No active database configuration found for client: {$clientName}\n";
                echo sprintf(
                    'Query executed: %s with params: %s',
                    $query,
                    json_encode(['client_name' => $clientName])
                ) . "\n";
                
                return null;
            }
            
            echo sprintf(
                'Found database configuration for client: %s. Host: %s, DB: %s',
                $clientName,
                $config['db_host'],
                $config['db_name']
            ) . "\n";

            echo 'Found database configuration for client: ' . $clientName . "\n";
            
            return [
                'driver' => 'mysql',
                'host' => $config['db_host'],
                'database' => $config['db_name'],
                'username' => $config['db_username'],
                'password' => $config['db_password'],
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'engine' => null,
            ];
            
        } catch (\PDOException $e) {
            // $this->logMessage("Error fetching client database config: " . $e->getMessage(), 'ERROR');
            echo "Error fetching client database config: " . $e->getMessage() . "\n";
            return null;
        }
    }

    public function initializeDatabase($auditData, $clientName) {
        try {
            // Get database configuration from environment variables
            $dbConfig = [
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => (int)(getenv('DB_PORT') ?: 3306),
                'dbname' => getenv('DB_DATABASE') ?: '',
                'username' => getenv('DB_USERNAME') ?: '',
                'password' => getenv('DB_PASSWORD') ?: '',
                'client_id' => getenv('CLIENT_ID') ?: 'document_service'
            ];
            // $this->logMessage("initializeDatabase: " . json_encode($dbConfig), 'ERROR');
            // Initialize the AuditLogModel with the database configuration
            $this->auditLogModel = new AuditLogModel($dbConfig['client_id']);
            
            // Set up database connection for the model
            if (method_exists($this->auditLogModel, 'setConnection')) {
                $this->auditLogModel->setConnection([
                    'driver' => 'mysql',
                    'host' => $dbConfig['host'],
                    'port' => $dbConfig['port'],
                    'database' => $dbConfig['dbname'],
                    'username' => $dbConfig['username'],
                    'password' => $dbConfig['password'],
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'prefix' => '',
                    'strict' => true,
                    'engine' => null,
                ]);
            }
            
            // $this->logMessage("Database connection initialized successfully");
            echo "Database connection initialized successfully\n";
            $this->createClientAuditLogModel($auditData, $clientName);
        } catch (Exception $e) {
            $this->logMessage("Failed to initialize database: " . $e->getMessage(), 'ERROR');
        }
    }
}
