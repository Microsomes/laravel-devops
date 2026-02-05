# Laravel DevOps

Docker scaffold generator for Laravel projects with separate dev/prod environments.

## Installation

```bash
composer require microsomes/laravel-devops
```

## Usage

### Generate Docker Configuration

```bash
php artisan docker:init
```

This will generate:

```
.docker/
├── dev/
│   ├── backend/Dockerfile
│   ├── frontend/Dockerfile
│   ├── frontend/conf.d/app.conf
│   └── node/Dockerfile
└── prod/
    ├── backend/Dockerfile
    ├── frontend/Dockerfile
    ├── frontend/conf.d/app.conf
    ├── docker-compose.yml
    └── .env.production

docker-compose.yml (dev)
```

### Options

```bash
# Overwrite existing files
php artisan docker:init --force

# Generate only development environment
php artisan docker:init --dev-only

# Generate only production environment
php artisan docker:init --prod-only
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=docker-scaffold-config
```

Then edit `config/docker-scaffold.php`:

```php
return [
    'project_name' => 'myapp',
    'network_name' => 'myapp-network',
    'php_version' => '8.2',
    'node_version' => '22',

    'ports' => [
        'frontend' => 8080,
        'vite' => 5173,
        'database' => 33066,
        'redis' => 63799,
        'mailhog_smtp' => 1025,
        'mailhog_web' => 8025,
    ],

    'database' => [
        'image' => 'mariadb:latest',
        'name' => 'laravel',
        'user' => 'root',
        'password' => 'password',
    ],

    'registry' => [
        'url' => 'registry.digitalocean.com/myregistry',
        'backend_image' => 'backend',
        'frontend_image' => 'frontend',
    ],

    'traefik' => [
        'enabled' => true,
        'domain' => 'example.com',
        'network' => 'traefik-public',
        'certresolver' => 'le',
    ],
];
```

## Development

Start the development environment:

```bash
docker compose up -d
```

Services available:
- **Frontend**: http://localhost:8080
- **Vite**: http://localhost:5173
- **MailHog**: http://localhost:8025
- **Database**: localhost:33066
- **Redis**: localhost:63799

## Production

### Build and push images

```bash
# Build images
docker build -t your-registry/backend:latest -f .docker/prod/backend/Dockerfile .
docker build -t your-registry/frontend:latest -f .docker/prod/frontend/Dockerfile .

# Push to registry
docker push your-registry/backend:latest
docker push your-registry/frontend:latest
```

### Deploy with Docker Swarm

```bash
cd .docker/prod
docker stack deploy -c docker-compose.yml myapp
```

## Architecture

### Development Environment

- **backend**: PHP-FPM container with Composer
- **frontend**: Nginx reverse proxy
- **node**: Vite dev server with HMR
- **database**: MariaDB
- **redis**: Redis for caching/queues
- **horizon**: Laravel Horizon queue worker
- **scheduler**: Laravel task scheduler
- **mailhog**: Email testing

### Production Environment

- **migrate**: One-time migration runner
- **backend**: Optimized PHP-FPM with built assets
- **frontend**: Nginx with static assets
- **database**: MariaDB with persistent volume
- **redis**: Redis for caching/queues
- Traefik integration for HTTPS

## License

MIT
