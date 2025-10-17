<?php

namespace RT\SharedComponents\Services;

use PDO;
use PDOException;

class DbService
{
    private $pdo;
    
    public function __construct($dbConfig)
    {
        $this->initializeDatabase($dbConfig);
    }
    
    /**
     * Initialize database connection
     */
    private function initializeDatabase($config)
    {
        try {
            $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Execute a SELECT query
     */
    public function select($sql, $params = [])
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            throw new \Exception("Select query failed: " . $e->getMessage());
        }
    }
    
    /**
     * Execute an INSERT query
     */
    public function insert($table, $data)
    {
        try {
            $columns = implode(',', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            
            $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
            $stmt = $this->pdo->prepare($sql);
            
            $result = $stmt->execute($data);
            
            if ($result) {
                return $this->pdo->lastInsertId();
            }
            
            return false;
        } catch (PDOException $e) {
            throw new \Exception("Insert query failed: " . $e->getMessage());
        }
    }
    
    /**
     * Execute an UPDATE query
     */
    public function update($table, $data, $where, $whereParams = [])
    {
        try {
            $setClause = [];
            foreach (array_keys($data) as $column) {
                $setClause[] = "{$column} = :{$column}";
            }
            $setClause = implode(', ', $setClause);
            
            $sql = "UPDATE {$table} SET {$setClause} WHERE {$where}";
            $stmt = $this->pdo->prepare($sql);
            
            $params = array_merge($data, $whereParams);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            throw new \Exception("Update query failed: " . $e->getMessage());
        }
    }
    
    /**
     * Execute a DELETE query
     */
    public function delete($table, $where, $whereParams = [])
    {
        try {
            $sql = "DELETE FROM {$table} WHERE {$where}";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($whereParams);
        } catch (PDOException $e) {
            throw new \Exception("Delete query failed: " . $e->getMessage());
        }
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction()
    {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit()
    {
        return $this->pdo->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollback()
    {
        return $this->pdo->rollBack();
    }
    
    /**
     * Get the PDO instance
     */
    public function getPdo()
    {
        return $this->pdo;
    }
}
