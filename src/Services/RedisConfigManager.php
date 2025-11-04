<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class RedisConfigManager
{
    const CACHE_PREFIX = 'client_db_config:';
    const CACHE_TTL = 86400; // 24 hours
    
    /**
     * Get database configuration for a client
     *
     * @param string $clientName
     * @return array
     * @throws \RuntimeException When configuration is not found
     */
    /**
     * @var ClientConfigCache
     */
    protected $cacheService;

    public function __construct(ClientConfigCache $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    public function getDbConfig(string $clientName): array
    {
        echo "\n[RedisConfigManager] Getting DB config for client: {$clientName}\n";
        
        // Try to get from cache first
        $cachedConfig = $this->cacheService->getConfig($clientName);
        echo "[RedisConfigManager] Cached config: " . ($cachedConfig ? 'found' : 'not found') . "\n";
        
        if ($cachedConfig !== null) {
            echo "[RedisConfigManager] Returning cached config\n";
            return $cachedConfig;
        }

        try {
            echo "[RedisConfigManager] Cache miss, fetching from database\n";
            $config = $this->fetchFromDatabase($clientName);
            echo "[RedisConfigManager] Database result: " . ($config ? 'found' : 'not found') . "\n";
            
            if ($config) {
                $configArray = (array)$config;
                echo "[RedisConfigManager] Storing in cache\n";
                $this->cacheService->storeConfig($clientName, $configArray);
                return $configArray;
            }
                
            throw new \RuntimeException("Database configuration not found for client: {$clientName}");
                
        } catch (\Exception $e) {
            echo "[RedisConfigManager] Error: " . $e->getMessage() . "\n";
            \Illuminate\Support\Facades\Log::error('Failed to get DB config', [
                'client' => $clientName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    /**
     * Fetch configuration from database
     */
    private function fetchFromDatabase(string $clientName)
    {
        echo "[RedisConfigManager] Fetching from database for client: {$clientName}\n";
        $result = DB::table('client_configs')
            ->where('client_name', $clientName)
            ->where('is_active', true)
            ->first();
        
        echo "[RedisConfigManager] Database query result: " . print_r($result, true) . "\n";
        return $result;
    }
    
    /**
     * Cache the configuration in Redis
     */
    private function cacheConfig(string $clientName, array $config): void
    {
        try {
            Redis::setex(
                self::CACHE_PREFIX . $clientName,
                self::CACHE_TTL,
                json_encode($config)
            );
        } catch (\Exception $e) {
            Log::error('Failed to cache DB config', [
                'client' => $clientName,
                'error' => $e->getMessage()
            ]);
            // Don't throw, continue without caching
        }
    }

    public function invalidateCache(string $clientName): bool
    {
        return $this->cacheService->invalidateConfig($clientName);
    }
}
