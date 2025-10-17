<?php

namespace RT\SharedComponents\Services;

use PDO;
use PDOException;
use RT\SharedComponents\Exceptions\DatabaseConfigException;

class DbConnectionManager
{
    /**
     * @var array Cache of database connections
     */
    private static $connections = [];

    /**
     * Get a database connection for a specific client
     *
     * @param string $clientId The client identifier
     * @param array $config Database configuration
     * @return PDO
     * @throws DatabaseConfigException
     */
    public static function getConnection(string $clientId, array $config): PDO
    {
        // Use default connection if no client ID is provided
        if (empty($clientId)) {
            throw new DatabaseConfigException('Client ID is required', $clientId);
        }

        // Check if connection already exists in cache
        if (isset(self::$connections[$clientId])) {
            return self::$connections[$clientId];
        }

        // Validate required config
        $required = ['host', 'database', 'username', 'password'];
        foreach ($required as $key) {
            if (!isset($config[$key])) {
                throw new DatabaseConfigException(
                    "Missing required database configuration: $key",
                    $clientId,
                    ['config_keys' => array_keys($config)]
                );
            }
        }

        // Build DSN
        $port = $config['port'] ?? '3306';
        $charset = $config['charset'] ?? 'utf8mb4';
        $dsn = "mysql:host={$config['host']};port={$port};dbname={$config['database']};charset=$charset";

        // Set PDO options
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        try {
            $pdo = new PDO($dsn, $config['username'], $config['password'], $options);
            self::$connections[$clientId] = $pdo;
            return $pdo;
        } catch (PDOException $e) {
            throw new DatabaseConfigException(
                "Failed to connect to database: " . $e->getMessage(),
                $clientId,
                [
                    'dsn' => preg_replace('/password=.*?([;\s]|$)/', 'password=***', $dsn),
                    'error_code' => $e->getCode(),
                ],
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Get a database connection by client ID
     *
     * @param string $clientId
     * @return PDO
     * @throws DatabaseConfigException
     */
    public static function getClientConnection(string $clientId): PDO
    {
        if (!isset(self::$connections[$clientId])) {
            throw new DatabaseConfigException(
                "No database connection found for client",
                $clientId
            );
        }
        return self::$connections[$clientId];
    }

    /**
     * Check if a client connection exists
     *
     * @param string $clientId
     * @return bool
     */
    public static function hasConnection(string $clientId): bool
    {
        return isset(self::$connections[$clientId]);
    }

    /**
     * Close a database connection
     *
     * @param string $clientId
     */
    public static function closeConnection(string $clientId): void
    {
        if (isset(self::$connections[$clientId])) {
            self::$connections[$clientId] = null;
            unset(self::$connections[$clientId]);
        }
    }

    /**
     * Close all database connections
     */
    public static function closeAllConnections(): void
    {
        foreach (array_keys(self::$connections) as $clientId) {
            self::closeConnection($clientId);
        }
    }

    /**
     * Get all active connection client IDs
     *
     * @return array
     */
    public static function getActiveConnections(): array
    {
        return array_keys(self::$connections);
    }

    /**
     * Register an existing PDO connection for a client
     *
     * @param string $clientId The client identifier
     * @param string $host Database host
     * @param string $database Database name
     * @param string $username Database username
     * @param string $password Database password
     * @param string $port Database port (default: 3306)
     * @param string $charset Database charset (default: utf8mb4)
     * @param PDO|null $pdo Optional existing PDO connection
     * @return PDO The PDO connection
     * @throws DatabaseConfigException
     */
    public static function registerConnection(
        string $clientId,
        string $host,
        string $database,
        string $username,
        string $password,
        string $port = '3306',
        string $charset = 'utf8mb4',
        ?PDO $pdo = null
    ): PDO {
        if (empty($clientId)) {
            throw new DatabaseConfigException('Client ID is required', $clientId);
        }

        // If PDO is provided, use it directly
        if ($pdo !== null) {
            self::$connections[$clientId] = $pdo;
            return $pdo;
        }

        // Otherwise create a new connection
        $config = [
            'host' => $host,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'port' => $port,
            'charset' => $charset,
        ];

        return self::getConnection($clientId, $config);
    }
}
