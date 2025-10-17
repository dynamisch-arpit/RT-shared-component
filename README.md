# RT Shared Components

A comprehensive PHP implementation for shared components including SQS FIFO queue management, audit trail processing, and database services for microservices architecture.

## Features

-  **RT Shared Components** - Centralized access to all shared services
-  **Queue Manager** - SQS FIFO queue management with publish/consume functionality
-  **Audit Trail Manager** - Service that subscribes to auditLogQueue for processing
-  **Database Service** - CRUD operations and transaction management
-  **Audit Log Model** - Database operations for audit trail storage
-  **Queue Helper** - Utilities for payload formatting and validation
-  **Redis Caching** - High-performance in-memory data store with configurable TTL
-  **Message deduplication and ordering** - FIFO queue support
-  **Error handling and logging** - Comprehensive error management

## Requirements

- PHP 7.4 or higher
- Composer
- AWS account with SQS permissions
- AWS credentials configured
- Redis server (optional, for caching)

## Installation

1. **Install dependencies:**

```bash
composer require rt/shared-components
```

### Manual Installation

1. Clone or download this repository
2. Run `composer install` to install dependencies
3. Include the autoloader in your project:

```php
require_once 'vendor/autoload.php';
use RT\SharedComponents\RT_Shared_Components;
```

## Features

- **Queue Management**: AWS SQS FIFO queue operations with publish/consume functionality
- **Audit Trail Management**: Automated audit log processing and storage
- **Database Services**: CRUD operations with transaction support
- **Redis Caching**: High-performance in-memory data store with configurable TTL
- **Helper Utilities**: Queue payload formatting, validation, and logging
- **Centralized Configuration**: Single entry point for all components
- **PSR-4 Autoloading**: Modern PHP namespace structure
- **Unit Testing**: PHPUnit test suite included

## Architecture

```
RT\SharedComponents\
├── Services\
│   ├── QueueManager         # SQS FIFO queue management
│   ├── AuditTrailManager    # Audit trail processing
│   └── DbService            # Database operations
├── Models\
│   └── AuditLogModel        # Audit log data model
├── Helpers\
│   └── QueueHelper          # Queue utilities
└── Queue\
    └── SqsFifoQueue.php     # Core SQS implementation
```

## Usage

### Basic RT Shared Components Usage

```php
<?php
require_once 'vendor/autoload.php';

use RT\SharedComponents\RT_Shared_Components;

// Get configuration with Redis settings
$config = [
    'region' => 'us-east-1',
    'version' => 'latest',
    'queue_prefix' => 'dev-',
    'database' => [
        'host' => 'localhost',
        'dbname' => 'your_database',
        'username' => 'db_user',
        'password' => 'db_password',
        'charset' => 'utf8mb4'
    ],
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => null,
        'database' => 0,
        'prefix' => 'rt_',
        'timeout' => 5
    ]
];

// Or load from environment variables
$config = ConfigHelper::getConfig([
    'region' => 'us-east-1',
    'version' => 'latest',
    'queue_prefix' => 'dev-',
    // ... other config
]);

$rt = new RT\SharedComponents\RT_Shared_Components($config);
```

### Using Redis Cache

```php
// Get Redis service
$redis = $rt->getRedisService();

// Set a value with 1 hour TTL
$redis->set('user:123', ['name' => 'John Doe', 'email' => 'john@example.com'], 3600);

// Get a value
$user = $redis->get('user:123');

// Check if key exists
if ($redis->has('user:123')) {
    // Key exists
}

// Delete a key
$redis->delete('user:123');

// Clear all keys with prefix
$redis->clear();
```

### Publishing Audit Events

```php
// Publish audit log message
$auditData = [
    'event_type' => 'U',
    'table_name' => 'tblDocument',
    'primary_key_value' => '123',
    'user_id' => 456,
    'ip_address' => '192.168.1.1',
    'url' => '/api/document/123',
    'old_values' => ['status' => 'draft'],
    'new_values' => ['status' => 'published'],
    'x_reference1' => 'doc_hash_123',
    'x_reference2' => '123'
];

$messageId = $rt->publish('', 'audit-log-queue.fifo', $auditData);
```

### Consuming Messages

```php
// Consume messages from queue
$messages = $rt->consume('', 'audit-log-queue.fifo');
echo "Consumed " . count($messages) . " messages\n";
```

### Starting Audit Trail Service

```php
// Start audit trail service (runs indefinitely)
$rt->startAuditTrailService();
```

### Direct Audit Processing

```php
// Process audit event directly (bypassing queue)
$auditTrailManager = $rt->getAuditTrailManager();
$auditId = $auditTrailManager->processAuditEventDirect($auditData);
```

## Examples

### 1. Complete Usage Example
```bash
php example_usage.php
```

This example demonstrates:
- Publishing audit log messages
- Consuming messages from queue
- Direct audit event processing
- Getting audit trail history
- Component status checking

## FIFO Queue Key Concepts

### Message Group ID
- Messages with the same Group ID are processed in order
- Different Group IDs can be processed in parallel
- Use Group IDs to partition your workload

### Message Deduplication ID
- Prevents duplicate messages within 5-minute deduplication interval
- Optional if `ContentBasedDeduplication` is enabled
- Must be unique per message group

### Content-Based Deduplication
- Automatically generates deduplication ID from message body
- Enabled by default in this implementation
- Useful when you can't generate unique IDs

## Configuration Options

The FIFO queue is configured with these attributes:

- **FifoQueue**: `true` - Enables FIFO ordering
- **ContentBasedDeduplication**: `true` - Auto-deduplication
- **MessageRetentionPeriod**: `1209600` seconds (14 days)
- **VisibilityTimeoutSeconds**: `30` seconds
- **ReceiveMessageWaitTimeSeconds**: `20` seconds (long polling)

## Error Handling

All methods include comprehensive error handling:

```php
$result = $fifoQueue->sendMessage($body, $groupId);
if ($result === false) {
    // Handle error - check logs for details
}
```

## Best Practices

1. **Use meaningful Group IDs** to partition workload
2. **Enable long polling** to reduce API calls
3. **Delete messages** after successful processing
4. **Handle errors gracefully** with retry logic
5. **Monitor queue metrics** in AWS CloudWatch

## AWS Permissions Required

Your AWS user/role needs these SQS permissions:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "sqs:CreateQueue",
                "sqs:DeleteQueue",
                "sqs:GetQueueUrl",
                "sqs:GetQueueAttributes",
                "sqs:SendMessage",
                "sqs:SendMessageBatch",
                "sqs:ReceiveMessage",
                "sqs:DeleteMessage"
            ],
            "Resource": "*"
        }
    ]
}
```

## Troubleshooting

### Common Issues

1. **Queue name must end with .fifo** for FIFO queues
2. **MessageGroupId is required** for all FIFO messages
3. **Deduplication ID** must be unique within 5-minute window
4. **AWS credentials** must be properly configured

### Debug Mode

Enable detailed logging by checking AWS SDK debug options in your configuration.

## License

This project is open source and available under the MIT License.
