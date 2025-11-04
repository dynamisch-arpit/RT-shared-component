# RT Shared Components

A comprehensive PHP implementation for shared components including SQS FIFO queue management, audit trail processing, and database services for microservices architecture.

## Features

- **RT Shared Components** - Centralized access to all shared services
- **Queue Manager** - SQS FIFO queue management with publish/consume functionality
- **Audit Trail Manager** - Service that subscribes to auditLogQueue for processing
- **Database Service** - CRUD operations and transaction management with PostgreSQL
- **Redis Caching** - High-performance in-memory data store with configurable TTL
- **Docker Support** - Containerized development and production environments
- **NGINX Web Server** - High-performance web server with optimized configuration
- **PHP 8.2** - Latest PHP version with optimized performance
- **Message Deduplication** - FIFO queue support with proper message ordering
- **Comprehensive Logging** - Centralized logging for all services

## Prerequisites

- Docker and Docker Compose
- AWS account with SQS permissions (if using AWS services)
- AWS credentials configured (if using AWS services)

## Quick Start with Docker

1. Clone the repository:
   ```bash
   git clone [repository-url]
   cd RT-shared-components
   ```

2. Copy the example environment file and update the values:
   ```bash
   cp .env.example .env
   ```

3. Start the services:
   ```bash
   docker-compose up -d
   ```

4. Install dependencies:
   ```bash
   docker-compose exec rt-api composer install
   ```

5. Access the application:
   - API: http://localhost:8081
   - Redis: localhost:6380

## Environment Variables

Create a `.env` file in the root directory with the following variables:

```
# Application
APP_ENV=development

# API Ports
API_PORT=8081
API_HTTPS_PORT=8443

# Redis
REDIS_PORT=6380

# Database (if applicable)
DB_HOST=postgres
DB_NAME=your_database
DB_USER=db_user
DB_PASSWORD=db_password

# AWS (if using SQS)
AWS_REGION=us-east-1
AWS_ACCESS_KEY_ID=your_access_key
AWS_SECRET_ACCESS_KEY=your_secret_key
```

## Development Setup

### Local Development

1. **Install dependencies:**
   ```bash
   composer install
   ```

2. **Start the development server:**
   ```bash
   docker-compose up -d
   ```

3. **Access the application:**
   - API: http://localhost:8081
   - Redis: localhost:6380

### Production Deployment

1. Update the `.env` file with production values
2. Build and start the containers:
   ```bash
   docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
   ```

## Project Structure

```
RT-shared-components/
├── api/                  # API endpoints and controllers
├── nginx/                # NGINX configuration
├── src/                  # Source code
│   ├── Services/         # Service classes
│   ├── Models/           # Data models
│   └── Helpers/          # Helper classes
├── .env                 # Environment variables
├── .env.example         # Example environment configuration
├── docker-compose.yml   # Docker Compose configuration
└── Dockerfile           # PHP-FPM container configuration
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

## Basic Usage

### Initialization

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use RT\SharedComponents\RT_Shared_Components;

// Configuration with Redis settings
$config = [
    'region' => getenv('AWS_REGION') ?: 'us-east-1',
    'version' => 'latest',
    'queue_prefix' => getenv('APP_ENV') === 'production' ? 'prod-' : 'dev-',
    'database' => [
        'host' => getenv('DB_HOST') ?: 'postgres',
        'dbname' => getenv('DB_NAME') ?: 'your_database',
        'username' => getenv('DB_USER') ?: 'db_user',
        'password' => getenv('DB_PASSWORD') ?: 'db_password',
        'charset' => 'utf8mb4'
    ],
    'redis' => [
        'host' => getenv('REDIS_HOST') ?: 'redis',
        'port' => getenv('REDIS_PORT') ?: 6379,
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'database' => getenv('REDIS_DB') ?: 0,
        'prefix' => 'rt_',
        'timeout' => 5
    ]
];

// Initialize the shared components
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

## Docker Commands

### Development

- Start services: `docker-compose up -d`
- Stop services: `docker-compose down`
- View logs: `docker-compose logs -f`
- Run tests: `docker-compose exec rt-api vendor/bin/phpunit`
- Enter container: `docker-compose exec rt-api bash`

### Production

- Build and start: `docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build`
- View logs: `docker-compose logs -f rt-api`
- Monitor: `docker stats`

## FIFO Queue Key Concepts

### Message Group ID
- Messages with the same Group ID are processed in order
- Different Group IDs can be processed in parallel
- Use Group IDs to partition your workload

## Troubleshooting

### Common Issues

1. **Port conflicts**: Ensure ports 8081, 8443, and 6380 are available
2. **Permission issues**: Run `chmod -R 777 logs/` if you encounter permission errors
3. **Container not starting**: Check logs with `docker-compose logs [service_name]`
4. **Dependency issues**: Run `docker-compose build --no-cache` to rebuild containers

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

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

# Docker Readme
# RT Shared Components - Docker Setup

This guide explains how to set up and use Docker for the RT Shared Components service.

## Prerequisites

- Docker Desktop (Windows/Mac) or Docker Engine (Linux)
- Docker Compose
- Git (for cloning the repository)

## Getting Started

### 1. Clone the Repository

```bash
git clone https://github.com/your-org/new-microservices.git
cd new-microservices/RT-shared-components
```

### 2. Set Up Environment Variables

Copy the example environment file and update it with your configuration:

```bash
cp .env.example .env
```

Edit the `.env` file with your database credentials and other settings.

### 3. Start the Services

Use the following command to start all services:

```bash
docker-compose up -d
```

This will start:
- RT API service (port 8081)
- MySQL database (port 3306)
- Redis (port 6379)
- PHPMyAdmin (port 8080)

### 4. Install Dependencies

Install PHP dependencies:

```bash
docker-compose exec rt-api composer install
```

### 5. Set Up Database

Run database migrations (if any):

```bash
docker-compose exec rt-api php artisan migrate
```

## Accessing Services

- **RT API**: http://localhost:8081
- **PHPMyAdmin**: http://localhost:8080
  - Username: `rt_user` (or as configured in .env)
  - Password: `rt_password` (or as configured in .env)

## Development Workflow

### Running Tests

```bash
docker-compose exec rt-api php vendor/bin/phpunit
```

### Viewing Logs

```bash
# API logs
docker-compose logs -f rt-api

# Database logs
docker-compose logs -f mysql

# Redis logs
docker-compose logs -f redis
```

## Creating a Docker Build Package

To create a `.dockerbuild.zip` file for deployment:

1. Run the script:
   ```bash
   .\create-dockerbuild.ps1
   ```

2. This will create a `.dockerbuild.zip` file in the project root that contains everything needed for deployment.

## Stopping Services

To stop all services:

```bash
docker-compose down
```

To stop and remove all containers, networks, and volumes:

```bash
docker-compose down -v
```

## Configuration

### Environment Variables

Key environment variables you might want to configure in `.env`:

```
# Application
APP_ENV=local
APP_DEBUG=true

# Database
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=rt_shared
DB_USERNAME=rt_user
DB_PASSWORD=rt_password

# Redis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

# API
API_PORT=8081
API_HTTPS_PORT=8443

# PHPMyAdmin
PMA_PORT=8080
```

## Troubleshooting

### Common Issues

1. **Port conflicts**: Ensure ports 8081, 3306, 6379, and 8080 are available.
2. **Permission issues**: On Linux, you might need to run Docker commands with `sudo`.
3. **Container not starting**: Check logs with `docker-compose logs <service_name>`.

### Resetting the Environment

To completely reset your development environment:

```bash
docker-compose down -v
rm -rf vendor/
docker system prune -a
```

Then follow the setup instructions again.

