<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Project Name
    |--------------------------------------------------------------------------
    |
    | Used for Docker network naming and service prefixes.
    | Defaults to the application name from config/app.php
    |
    */
    'project_name' => env('DOCKER_PROJECT_NAME', strtolower(str_replace(' ', '', config('app.name', 'laravel')))),

    /*
    |--------------------------------------------------------------------------
    | Network Name
    |--------------------------------------------------------------------------
    |
    | The Docker network name for internal container communication.
    |
    */
    'network_name' => env('DOCKER_NETWORK_NAME', 'app-network'),

    /*
    |--------------------------------------------------------------------------
    | PHP Version
    |--------------------------------------------------------------------------
    |
    | The PHP version to use in the backend Dockerfile.
    |
    */
    'php_version' => env('DOCKER_PHP_VERSION', '8.2'),

    /*
    |--------------------------------------------------------------------------
    | Node Version
    |--------------------------------------------------------------------------
    |
    | The Node.js version to use for asset compilation.
    |
    */
    'node_version' => env('DOCKER_NODE_VERSION', '22'),

    /*
    |--------------------------------------------------------------------------
    | Ports Configuration
    |--------------------------------------------------------------------------
    |
    | External ports for development environment services.
    |
    */
    'ports' => [
        'frontend' => env('DOCKER_PORT_FRONTEND', 8080),
        'vite' => env('DOCKER_PORT_VITE', 5173),
        'database' => env('DOCKER_PORT_DATABASE', 33066),
        'redis' => env('DOCKER_PORT_REDIS', 63799),
        'mailhog_smtp' => env('DOCKER_PORT_MAILHOG_SMTP', 1025),
        'mailhog_web' => env('DOCKER_PORT_MAILHOG_WEB', 8025),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    |
    | Database settings for the MariaDB container.
    |
    */
    'database' => [
        'image' => env('DOCKER_DB_IMAGE', 'mariadb:latest'),
        'name' => env('DOCKER_DB_NAME', 'laravel'),
        'user' => env('DOCKER_DB_USER', 'root'),
        'password' => env('DOCKER_DB_PASSWORD', 'password'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Production Registry
    |--------------------------------------------------------------------------
    |
    | Container registry URL for production images.
    |
    */
    'registry' => [
        'url' => env('DOCKER_REGISTRY_URL', 'registry.digitalocean.com/myregistry'),
        'backend_image' => env('DOCKER_REGISTRY_BACKEND', 'backend'),
        'frontend_image' => env('DOCKER_REGISTRY_FRONTEND', 'frontend'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Traefik Configuration (Production)
    |--------------------------------------------------------------------------
    |
    | Traefik reverse proxy settings for production deployment.
    |
    */
    'traefik' => [
        'enabled' => env('DOCKER_TRAEFIK_ENABLED', true),
        'domain' => env('DOCKER_TRAEFIK_DOMAIN', 'example.com'),
        'network' => env('DOCKER_TRAEFIK_NETWORK', 'traefik-public'),
        'certresolver' => env('DOCKER_TRAEFIK_CERTRESOLVER', 'le'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Services to Include
    |--------------------------------------------------------------------------
    |
    | Toggle which services to include in the generated compose files.
    |
    */
    'services' => [
        'backend' => true,
        'frontend' => true,
        'node' => true,           // Dev only: Vite dev server
        'database' => true,
        'redis' => true,
        'mailhog' => true,        // Dev only
        'horizon' => true,        // Queue worker
        'scheduler' => true,      // Task scheduler
    ],
];
