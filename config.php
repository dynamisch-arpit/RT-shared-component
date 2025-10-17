<?php

// Load environment variables from .env file
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue; // Skip comments
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

return [
    'aws' => [
        'region' => getenv('SQS_QUEUE_REGION') ?: getenv('AWS_DEFAULT_REGION') ?: 'us-east-2',
        'version' => 'latest',
        'credentials' => [
            'key' => getenv('AWS_ACCESS_KEY_ID'),
            'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
        ]
    ],
    'sqs' => [
        'queue_name' => getenv('SQS_QUEUE_NAME') ?: 'audit-log-queue.fifo', // FIFO queues must end with .fifo
        'queue_url' => getenv('SQS_QUEUE_URL') ?: '', // Will be populated after queue creation
        'region' => getenv('SQS_QUEUE_REGION') ?: 'ap-south-1',
    ],
    'redis' => [
        'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
        'port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
        'password' => $_ENV['REDIS_PASSWORD'] ?? null,
        'database' => (int) ($_ENV['REDIS_DATABASE'] ?? 0),
        'prefix' => $_ENV['REDIS_PREFIX'] ?? 'rt_',
        'timeout' => (int) ($_ENV['REDIS_TIMEOUT'] ?? 5),
    ],
    'database' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'dbname' => getenv('DB_NAME') ?: 'your_database',
        'username' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
        'client_id' => $_ENV['DB_CLIENT_ID'] ?? 'default_client',
    ]
];

