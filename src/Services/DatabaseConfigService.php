<?php

namespace RT\SharedComponents\Services;

use RT\SharedComponents\Services\RedisService;
use RT\SharedComponents\Services\DbConnectionManager;
use RT\SharedComponents\Exceptions\DatabaseConfigException;
use PDO;

class DatabaseConfigService
{
    /**
     * @var RedisService
     */
    private $redis;
    
    /**
     * @var array
     */
    private $config;
    
    /**
     * Cache key for database configurations
     */
    const CACHE_KEY_PREFIX = 'db:config:';
    
    /**
     * Default cache TTL (24 hours)
     */
    const DEFAULT_CACHE_TTL = 86400;

    public function __construct(RedisService $redis, array $config = [])
    {
        $this->redis = $redis;
        $this->config = array_merge([
            'cache_ttl' => self::DEFAULT_CACHE_TTL,
            'prefix' => self::CACHE_KEY_PREFIX,
        ], $config);
    }
    
    /**
     * Get database configuration for a client
     *
     * @param string $clientId
     * @return array
     * @throws DatabaseConfigException
     */
    public function getClientConfig(string $clientId): array
    {
        // Check if configuration is cached in Redis
        $cacheKey = $this->getCacheKey($clientId);
        $cachedConfig = $this->redis->get($cacheKey);
        
        if ($cachedConfig !== false) {
            return json_decode($cachedConfig, true);
        }
        
        // If not in cache, load from source
        $config = $this->loadClientConfig($clientId);
        
        // Cache the configuration
        $this->cacheClientConfig($clientId, $config);
        
        return $config;
    }
    
    /**
     * Get a database connection for a client
     *
     * @param string $clientId
     * @return PDO
     * @throws DatabaseConfigException
     */
    public function getClientConnection(string $clientId): PDO
    {
        // First check if we already have a connection
        if (DbConnectionManager::hasConnection($clientId)) {
            return DbConnectionManager::getClientConnection($clientId);
        }
        
        // Get the client's database configuration
        $config = $this->getClientConfig($clientId);
        
        // Create and return a new connection
        return DbConnectionManager::getConnection($clientId, $config);
    }
    
    /**
     * Close a client's database connection
     *
     * @param string $clientId
     * @return void
     */
    public function closeClientConnection(string $clientId): void
    {
        DbConnectionManager::closeConnection($clientId);
    }
    
    /**
     * Update database configuration for a client
     */
    public function updateClientConfig(string $clientId, array $config): bool
    {
        $cacheKey = $this->getCacheKey($clientId);
        
        // Update in database or master config
        $result = $this->saveClientConfig($clientId, $config);
        
        if ($result) {
            // Update cache
            $this->redis->set($cacheKey, $config, $this->config['cache_ttl']);
        }
        
        return $result;
    }
    
    /**
     * Invalidate cache for a client's database config
     */
    public function invalidateClientConfig(string $clientId): bool
    {
        $cacheKey = $this->getCacheKey($clientId);
        return $this->redis->delete($cacheKey);
    }
    
    /**
     * Get all client configurations (use with caution for large numbers of clients)
     */
    public function getAllClientConfigs(): array
    {
        $keys = $this->redis->keys($this->config['prefix'] . '*');
        $configs = [];
        
        foreach ($keys as $key) {
            $clientId = str_replace($this->config['prefix'], '', $key);
            $configs[$clientId] = $this->getClientConfig($clientId);
        }
        
        return $configs;
    }
    
    /**
     * Load client configuration from master source (database or config file)
     */
    protected function loadClientConfig(string $clientId): array
    {
        // TODO: Implement loading from your master configuration source
        // This could be a database, config file, or environment variables
        // For now, we'll return a default configuration
        
        // Example: Load from environment variables with client-specific prefix
        $prefix = strtoupper($clientId) . '_DB_';
        
        $config = [
            'driver' => getenv($prefix . 'DRIVER') ?: 'mysql',
            'host' => getenv($prefix . 'HOST') ?: 'localhost',
            'port' => getenv($prefix . 'PORT') ?: '3306',
            'database' => getenv($prefix . 'DATABASE') ?: $clientId . '_db',
            'username' => getenv($prefix . 'USERNAME') ?: 'root',
            'password' => getenv($prefix . 'PASSWORD') ?: '',
            'charset' => getenv($prefix . 'CHARSET') ?: 'utf8mb4',
            'collation' => getenv($prefix . 'COLLATION') ?: 'utf8mb4_unicode_ci',
            'prefix' => getenv($prefix . 'PREFIX') ?: '',
        ];
        
        return $config;
    }
    
    /**
     * Save client configuration to master source
     */
    protected function saveClientConfig(string $clientId, array $config): bool
    {
        // TODO: Implement saving to your master configuration source
        // This could be a database or config file
        // For now, we'll just return true as a placeholder
        
        // Example: Save to environment variables (in a real app, you'd use a database)
        $prefix = strtoupper($clientId) . '_DB_';
        
        foreach ($config as $key => $value) {
            $envKey = $prefix . strtoupper($key);
            putenv("$envKey=$value");
            $_ENV[$envKey] = $value;
            $_SERVER[$envKey] = $value;
        }
        
        return true;
    }
    
    /**
     * Generate cache key for a client
     */
    protected function getCacheKey(string $clientId): string
    {
        return $this->config['prefix'] . $clientId;
    }
}
