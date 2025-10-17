<?php

namespace RT\SharedComponents\Helpers;

class ConfigHelper
{
    /**
     * Get Redis configuration from environment variables
     *
     * @return array
     */
    public static function getRedisConfig(): array
    {
        return [
            'scheme' => 'tcp',
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('REDIS_PORT') ?: 6379),
            'password' => getenv('REDIS_PASSWORD') ?: null,
            'database' => (int)(getenv('REDIS_DATABASE') ?: 0),
            'prefix' => getenv('REDIS_PREFIX') ?: 'rt_',
            'timeout' => (int)(getenv('REDIS_TIMEOUT') ?: 5),
        ];
    }
    
    /**
     * Get full configuration including Redis settings
     * 
     * @param array $customConfig Custom configuration to merge
     * @return array
     */
    public static function getConfig(array $customConfig = []): array
    {
        $defaultConfig = [
            'redis' => self::getRedisConfig(),
            // Add other default configurations here
        ];
        
        return array_merge($defaultConfig, $customConfig);
    }
}
