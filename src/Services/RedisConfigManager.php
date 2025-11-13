<?php

namespace RT\SharedComponents\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use RT\SharedComponents\Services\ClientConfigCache;

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

    public function getDbConfig(string $clientName): object
    {        
        // Try to get from cache first
        $cachedConfig = $this->cacheService->getConfig($clientName);
        
        if ($cachedConfig !== null) {
            $cachedConfigobject = json_decode(json_encode($cachedConfig));
            return $cachedConfigobject;
        }

        try {
            $config = $this->fetchFromDatabase($clientName);
            
            if ($config) {
                $configArray = (array)$config;
                $this->cacheService->storeConfig($clientName, $configArray);
                $cachedConfigobject = json_decode(json_encode($configArray));
                return $cachedConfigobject;
            }
                
            throw new \RuntimeException("Database configuration not found for client: {$clientName}");
                
        } catch (\Exception $e) {
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
        $result = DB::table('client_configs')
            ->where('client_name', $clientName)
            ->where('is_active', true)
            ->first();
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
