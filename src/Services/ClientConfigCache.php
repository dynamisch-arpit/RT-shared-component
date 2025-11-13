<?php

namespace RT\SharedComponents\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class ClientConfigCache
{
    const CACHE_PREFIX = 'client:config:';
    const CACHE_TTL = 86400; // 24 hours

    /**
     * Get client configuration from cache
     *
     * @param string $clientName
     * @return array|null
     */
    public function getConfig(string $clientName): ?array
    {
        try {
            $cacheKey = $this->getCacheKey($clientName);
            
            $cached = Redis::get($cacheKey);
            
            if ($cached) {
                $decoded = json_decode($cached, true);
                return $decoded;
            }
            return null;
        } catch (\Exception $e) {
            Log::error('Failed to get client config from cache', [
                'client' => $clientName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Store client configuration in cache
     *
     * @param string $clientName
     * @param array $config
     * @return bool
     */
    public function storeConfig(string $clientName, array $config): bool
    {
        try {            
            $result = (bool) Redis::setex(
                $this->getCacheKey($clientName),
                self::CACHE_TTL,
                json_encode($config)
            );
            return $result;
        } catch (\Exception $e) {
            Log::error('Failed to store client config in cache', [
                'client' => $clientName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Invalidate client configuration cache
     *
     * @param string $clientName
     * @return bool
     */
    public function invalidateConfig(string $clientName): bool
    {
        try {
            return (bool) Redis::del($this->getCacheKey($clientName));
        } catch (\Exception $e) {
            Log::error('Failed to invalidate client config cache', [
                'client' => $clientName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get cache key for client configuration
     *
     * @param string $clientName
     * @return string
     */
    protected function getCacheKey(string $clientName): string
    {
        return self::CACHE_PREFIX . $clientName;
    }

    /**
     * Get multiple client configurations at once
     *
     * @param array $clientNames
     * @return array
     */
    public function getMultiple(array $clientNames): array
    {
        $result = [];
        $keysToFetch = [];
        
        // First try to get from cache
        foreach ($clientNames as $clientName) {
            $cached = $this->getConfig($clientName);
            if ($cached !== null) {
                $result[$clientName] = $cached;
            } else {
                $keysToFetch[] = $clientName;
            }
        }
        
        // If we have misses, fetch from database
        if (!empty($keysToFetch)) {
            $dbConfigs = $this->fetchMultipleFromDatabase($keysToFetch);
            foreach ($dbConfigs as $clientName => $config) {
                $this->storeConfig($clientName, (array)$config);
                $result[$clientName] = (array)$config;
            }
        }
        
        return $result;
    }

    /**
     * Fetch multiple client configurations from database
     *
     * @param array $clientNames
     * @return array
     */
    private function fetchMultipleFromDatabase(array $clientNames): array
    {
        try {
            return DB::table('client_configs')
                ->whereIn('client_name', $clientNames)
                ->where('is_active', true)
                ->get()
                ->keyBy('client_name')
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Failed to fetch multiple client configs from database', [
                'clients' => $clientNames,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Store multiple client configurations in cache
     *
     * @param array $configs [clientName => config, ...]
     * @return array List of successfully stored client names
     */
    public function storeMultiple(array $configs): array
    {
        $stored = [];
        foreach ($configs as $clientName => $config) {
            if ($this->storeConfig($clientName, (array)$config)) {
                $stored[] = $clientName;
            }
        }
        return $stored;
    }

    /**
     * Invalidate multiple client configuration caches
     *
     * @param array $clientNames
     * @return int Number of successfully invalidated caches
     */
    public function invalidateMultiple(array $clientNames): int
    {
        $count = 0;
        foreach ($clientNames as $clientName) {
            if ($this->invalidateConfig($clientName)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get all cached client configurations
     *
     * @param callable|null $filter Optional filter function (clientName => config)
     * @return array
     */
    public function getAllCached(\Closure $filter = null): array
    {
        $keys = $this->getAllCacheKeys();
        $result = [];
        
        foreach ($keys as $key) {
            $clientName = str_replace(self::CACHE_PREFIX, '', $key);
            $config = $this->getConfig($clientName);
            
            if ($config !== null && ($filter === null || $filter($clientName, $config))) {
                $result[$clientName] = $config;
            }
        }
        
        return $result;
    }

    /**
     * Get all cache keys matching the prefix
     *
     * @return array
     */
    public function getAllCacheKeys(): array
    {
        try {
            return Redis::keys($this->getCacheKey('*'));
        } catch (\Exception $e) {
            Log::error('Failed to get all cache keys', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Clear all cached client configurations
     *
     * @return int Number of deleted cache entries
     */
    public function clearAllCache(): int
    {
        $keys = $this->getAllCacheKeys();
        if (empty($keys)) {
            return 0;
        }
        
        try {
            return Redis::del($keys);
        } catch (\Exception $e) {
            Log::error('Failed to clear all cache', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Check if a client configuration is cached
     *
     * @param string $clientName
     * @return bool
     */
    public function isCached(string $clientName): bool
    {
        try {
            return (bool) Redis::exists($this->getCacheKey($clientName));
        } catch (\Exception $e) {
            Log::error('Failed to check if config is cached', [
                'client' => $clientName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get remaining TTL for a cached client configuration
     *
     * @param string $clientName
     * @return int|null TTL in seconds, null if key doesn't exist or error
     */
    public function getTtl(string $clientName): ?int
    {
        try {
            $ttl = Redis::ttl($this->getCacheKey($clientName));
            return $ttl >= 0 ? $ttl : null;
        } catch (\Exception $e) {
            Log::error('Failed to get TTL for client config', [
                'client' => $clientName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    public function getStats(): array
    {
        $keys = $this->getAllCacheKeys();
        $stats = [
            'total' => count($keys),
            'expiring_soon' => 0,
            'memory_usage' => 0,
        ];

        foreach ($keys as $key) {
            try {
                $ttl = Redis::ttl($key);
                if ($ttl > 0 && $ttl < 3600) { // Expiring in less than 1 hour
                    $stats['expiring_soon']++;
                }
                // Memory usage is an estimate since Redis doesn't provide exact memory per key
                $stats['memory_usage'] += strlen($key) + (int)Redis::strlen($key);
            } catch (\Exception $e) {
                continue;
            }
        }

        return $stats;
    }
}
