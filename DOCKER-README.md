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
