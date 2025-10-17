<?php

if (!function_exists('rt_log_message')) {
    /**
     * Log a message with timestamp and log level
     *
     * @param string $message The message to log
     * @param string $level The log level (INFO, ERROR, WARN, DEBUG, etc.)
     * @param string|null $logFile Path to the log file (optional)
     */
    function rt_log_message($message, $level = 'INFO', $logFile = null) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
        
        if ($logFile) {
            error_log($logMessage, 3, $logFile);
        } else {
            error_log($logMessage);
        }
    }
}

if (!function_exists('rt_get_env')) {
    /**
     * Get an environment variable with a default value
     *
     * @param string $key The environment variable name
     * @param mixed $default Default value if the variable is not set
     * @return mixed
     */
    function rt_get_env($key, $default = null) {
        $value = getenv($key);
        return $value !== false ? $value : $default;
    }
}

// Add any additional helper functions below
