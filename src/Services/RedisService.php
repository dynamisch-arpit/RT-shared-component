<?php

namespace RT\SharedComponents\Services;

use Predis\Client as PredisClient;
use Exception;

class RedisService
{
    /**
     * @var PredisClient
     */
    private $client;

    /**
     * @var array
     */
    private $config;

    /**
     * RedisService constructor.
     *
     * @param array $config Redis configuration
     * @throws Exception
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'scheme' => 'tcp',
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => null,
            'database' => 0,
            'prefix' => 'rt_',
            'timeout' => 5,
            'read_write_timeout' => 0,
        ], $config);

        try {
            $this->client = new PredisClient([
                'scheme' => $this->config['scheme'],
                'host' => $this->config['host'],
                'port' => $this->config['port'],
                'password' => $this->config['password'],
                'database' => $this->config['database'],
                'prefix' => $this->config['prefix'],
                'timeout' => $this->config['timeout'],
                'read_write_timeout' => $this->config['read_write_timeout'],
            ]);
            
            // Test connection
            $this->client->ping();
        } catch (\Exception $e) {
            throw new Exception("Redis connection failed: " . $e->getMessage());
        }
    }

    /**
     * Get a value from cache
     *
     * @param string $key
     * @return mixed|null
     */
    public function get(string $key)
    {
        try {
            $value = $this->client->get($key);
            return $value !== null ? json_decode($value, true) : null;
        } catch (\Exception $e) {
            error_log("Redis get failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Set a value in cache
     *
     * @param string $key
     * @param mixed $value
     * @param int $ttl Time to live in seconds (0 for no expiration)
     * @return bool
     */
    public function set(string $key, $value, int $ttl = 0): bool
    {
        try {
            $serialized = json_encode($value);
            if ($ttl > 0) {
                return (bool)$this->client->setex($key, $ttl, $serialized);
            }
            return (bool)$this->client->set($key, $serialized);
        } catch (\Exception $e) {
            error_log("Redis set failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a key from cache
     *
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        try {
            return (bool)$this->client->del([$key]);
        } catch (\Exception $e) {
            error_log("Redis delete failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a key exists
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        try {
            return (bool)$this->client->exists($key);
        } catch (\Exception $e) {
            error_log("Redis has failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear all keys with the current prefix
     *
     * @return bool
     */
    public function clear(): bool
    {
        try {
            $keys = $this->client->keys('*');
            if (!empty($keys)) {
                $this->client->del($keys);
            }
            return true;
        } catch (\Exception $e) {
            error_log("Redis clear failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the underlying Redis client
     *
     * @return PredisClient
     */
    public function getClient(): PredisClient
    {
        return $this->client;
    }
}
