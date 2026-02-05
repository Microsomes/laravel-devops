<?php

namespace Microsomes\LaravelDevops\Services;

use Symfony\Component\Yaml\Yaml;

class ComposeBuilder
{
    protected array $config;
    protected array $selections;

    public function __construct(array $config, array $selections)
    {
        $this->config = $config;
        $this->selections = $selections;
    }

    public function buildDevCompose(): string
    {
        $services = [];
        $networkName = $this->config['network_name'];

        // Backend service (always included)
        $services['backend'] = [
            'build' => [
                'context' => '.',
                'dockerfile' => '.docker/dev/backend/Dockerfile',
            ],
            'restart' => 'unless-stopped',
            'tty' => true,
            'volumes' => ['./:/var/www'],
            'networks' => [$networkName],
        ];

        // Frontend service (always included)
        $services['frontend'] = [
            'build' => [
                'context' => '.',
                'dockerfile' => '.docker/dev/frontend/Dockerfile',
            ],
            'restart' => 'unless-stopped',
            'tty' => true,
            'volumes' => ['./:/var/www'],
            'networks' => [$networkName],
            'ports' => ["{$this->config['ports']['frontend']}:80"],
        ];

        // Database service
        $dbConfig = $this->selections['dev']['database'];
        if ($dbConfig['enabled'] && $dbConfig['driver'] !== 'sqlite' && $dbConfig['driver'] !== 'none') {
            $services['database'] = $this->buildDatabaseService($dbConfig['driver'], 'dev');
        }

        // Redis service
        if ($this->selections['dev']['redis']) {
            $services['redis'] = [
                'image' => 'redis:alpine',
                'restart' => 'unless-stopped',
                'ports' => ["{$this->config['ports']['redis']}:6379"],
                'networks' => [$networkName],
            ];
        }

        // Node/Vite service
        $services['node'] = [
            'build' => [
                'context' => '.',
                'dockerfile' => '.docker/dev/node/Dockerfile',
            ],
            'tty' => true,
            'networks' => [$networkName],
            'command' => 'sh -c "npm install && npm run dev -- --host 0.0.0.0"',
            'ports' => ["{$this->config['ports']['vite']}:5173"],
            'volumes' => ['./:/var/www'],
        ];

        // Horizon service
        if ($this->selections['dev']['horizon']) {
            $depends = [];
            if ($this->selections['dev']['redis']) {
                $depends[] = 'redis';
            }
            if ($dbConfig['enabled'] && $dbConfig['driver'] !== 'sqlite' && $dbConfig['driver'] !== 'none') {
                $depends[] = 'database';
            }

            $services['horizon'] = [
                'build' => [
                    'context' => '.',
                    'dockerfile' => '.docker/dev/backend/Dockerfile',
                ],
                'restart' => 'unless-stopped',
                'volumes' => ['./:/var/www'],
                'networks' => [$networkName],
                'command' => 'php artisan horizon',
            ];

            if (!empty($depends)) {
                $services['horizon']['depends_on'] = $depends;
            }
        }

        // Scheduler service
        if ($this->selections['dev']['scheduler']) {
            $depends = [];
            if ($dbConfig['enabled'] && $dbConfig['driver'] !== 'sqlite' && $dbConfig['driver'] !== 'none') {
                $depends[] = 'database';
            }

            $services['scheduler'] = [
                'build' => [
                    'context' => '.',
                    'dockerfile' => '.docker/dev/backend/Dockerfile',
                ],
                'restart' => 'unless-stopped',
                'volumes' => ['./:/var/www'],
                'networks' => [$networkName],
                'command' => 'php artisan schedule:work',
            ];

            if (!empty($depends)) {
                $services['scheduler']['depends_on'] = $depends;
            }
        }

        // Mailhog service
        if ($this->selections['dev']['mailhog']) {
            $services['mailhog'] = [
                'image' => 'mailhog/mailhog',
                'restart' => 'unless-stopped',
                'ports' => [
                    "{$this->config['ports']['mailhog_smtp']}:1025",
                    "{$this->config['ports']['mailhog_web']}:8025",
                ],
                'networks' => [$networkName],
            ];
        }

        $compose = [
            'services' => $services,
            'networks' => [
                $networkName => [
                    'driver' => 'bridge',
                ],
            ],
        ];

        return Yaml::dump($compose, 10, 2);
    }

    public function buildProdCompose(): string
    {
        $services = [];
        $networkName = $this->config['network_name'];
        $traefikNetwork = $this->config['traefik']['network'];
        $registryUrl = $this->config['registry']['url'];
        $backendImage = $this->config['registry']['backend_image'];
        $frontendImage = $this->config['registry']['frontend_image'];
        $projectName = $this->config['project_name'];
        $domain = $this->config['traefik']['domain'];
        $certResolver = $this->config['traefik']['certresolver'];

        $dbConfig = $this->selections['prod']['database'];

        // Migrate service
        if ($this->selections['prod']['migrate']) {
            $migrateDepends = [];
            if ($dbConfig['enabled'] && $dbConfig['driver'] !== 'none') {
                $migrateDepends[] = 'database';
            }

            $services['migrate'] = [
                'image' => "{$registryUrl}/{$backendImage}:latest",
                'command' => 'php artisan migrate --force',
                'networks' => [$networkName],
                'deploy' => [
                    'replicas' => 1,
                    'restart_policy' => [
                        'condition' => 'on-failure',
                        'max_attempts' => 3,
                    ],
                ],
            ];

            if (!empty($migrateDepends)) {
                $services['migrate']['depends_on'] = $migrateDepends;
            }
        }

        // Deploy jobs (one-off commands)
        if (!empty($this->selections['prod']['deploy_jobs'])) {
            $commands = array_map(function ($cmd) {
                return "php artisan {$cmd}";
            }, $this->selections['prod']['deploy_jobs']);

            $deployDepends = [];
            if ($this->selections['prod']['migrate']) {
                $deployDepends[] = 'migrate';
            }

            $services['deploy-jobs'] = [
                'image' => "{$registryUrl}/{$backendImage}:latest",
                'command' => 'sh -c "' . implode(' && ', $commands) . '"',
                'networks' => [$networkName],
                'deploy' => [
                    'replicas' => 1,
                    'restart_policy' => [
                        'condition' => 'none',
                    ],
                ],
            ];

            if (!empty($deployDepends)) {
                $services['deploy-jobs']['depends_on'] = $deployDepends;
            }
        }

        // Backend service
        $backendDepends = [];
        if ($this->selections['prod']['migrate']) {
            $backendDepends[] = 'migrate';
        }

        $services['backend'] = [
            'image' => "{$registryUrl}/{$backendImage}:latest",
            'restart' => 'unless-stopped',
            'tty' => true,
            'networks' => [$networkName],
            'environment' => [
                'ASSET_URL' => "https://{$domain}",
            ],
            'deploy' => [
                'replicas' => 1,
                'restart_policy' => [
                    'condition' => 'on-failure',
                    'delay' => '5s',
                    'max_attempts' => 3,
                ],
            ],
        ];

        if (!empty($backendDepends)) {
            $services['backend']['depends_on'] = $backendDepends;
        }

        // Frontend service with Traefik labels
        $services['frontend'] = [
            'image' => "{$registryUrl}/{$frontendImage}:latest",
            'restart' => 'unless-stopped',
            'tty' => true,
            'networks' => [$networkName, $traefikNetwork],
            'ports' => ["{$this->config['ports']['frontend']}:80"],
            'depends_on' => ['backend'],
            'deploy' => [
                'replicas' => 1,
                'restart_policy' => [
                    'condition' => 'on-failure',
                    'delay' => '5s',
                    'max_attempts' => 3,
                ],
                'labels' => [
                    'traefik.enable=true',
                    "traefik.http.routers.{$projectName}.rule=Host(`{$domain}`)",
                    "traefik.http.routers.{$projectName}.entrypoints=websecure",
                    "traefik.http.routers.{$projectName}.tls=true",
                    "traefik.http.routers.{$projectName}.tls.certresolver={$certResolver}",
                    "traefik.http.services.{$projectName}.loadbalancer.server.port=80",
                    "traefik.docker.network={$traefikNetwork}",
                ],
            ],
        ];

        // Redis service
        if ($this->selections['prod']['redis']) {
            $services['redis'] = [
                'image' => 'redis:alpine',
                'restart' => 'unless-stopped',
                'networks' => [$networkName],
            ];
        }

        // Database service
        if ($dbConfig['enabled'] && $dbConfig['driver'] !== 'none') {
            $services['database'] = $this->buildDatabaseService($dbConfig['driver'], 'prod');
        }

        // Horizon service
        if ($this->selections['prod']['horizon']) {
            $horizonDepends = ['backend'];
            if ($this->selections['prod']['redis']) {
                $horizonDepends[] = 'redis';
            }

            $services['horizon'] = [
                'image' => "{$registryUrl}/{$backendImage}:latest",
                'command' => 'php artisan horizon',
                'restart' => 'unless-stopped',
                'networks' => [$networkName],
                'depends_on' => $horizonDepends,
                'deploy' => [
                    'replicas' => 1,
                    'restart_policy' => [
                        'condition' => 'on-failure',
                        'delay' => '5s',
                        'max_attempts' => 3,
                    ],
                ],
            ];
        }

        // Scheduler service
        if ($this->selections['prod']['scheduler']) {
            $services['scheduler'] = [
                'image' => "{$registryUrl}/{$backendImage}:latest",
                'command' => 'php artisan schedule:work',
                'restart' => 'unless-stopped',
                'networks' => [$networkName],
                'depends_on' => ['backend'],
                'deploy' => [
                    'replicas' => 1,
                    'restart_policy' => [
                        'condition' => 'on-failure',
                        'delay' => '5s',
                        'max_attempts' => 3,
                    ],
                ],
            ];
        }

        // Deploy services (persistent workers)
        foreach ($this->selections['prod']['deploy_services'] as $index => $command) {
            $serviceName = 'worker-' . ($index + 1);
            $services[$serviceName] = [
                'image' => "{$registryUrl}/{$backendImage}:latest",
                'command' => "php artisan {$command}",
                'restart' => 'unless-stopped',
                'networks' => [$networkName],
                'depends_on' => ['backend'],
                'deploy' => [
                    'replicas' => 1,
                    'restart_policy' => [
                        'condition' => 'on-failure',
                        'delay' => '5s',
                        'max_attempts' => 3,
                    ],
                ],
            ];
        }

        $compose = [
            'services' => $services,
            'networks' => [
                $networkName => [],
                $traefikNetwork => [
                    'external' => true,
                ],
            ],
        ];

        // Add volumes if database is enabled
        if ($dbConfig['enabled'] && $dbConfig['driver'] !== 'none') {
            $volumeName = $this->getDatabaseVolumeName($dbConfig['driver']);
            $compose['volumes'] = [
                $volumeName => [],
            ];
        }

        return Yaml::dump($compose, 10, 2);
    }

    protected function buildDatabaseService(string $driver, string $environment): array
    {
        $networkName = $this->config['network_name'];
        $image = $this->config['database_images'][$driver] ?? 'mariadb:latest';
        $dbName = $this->config['database']['name'];
        $dbUser = $this->config['database']['user'];
        $dbPassword = $this->config['database']['password'];

        if ($driver === 'postgresql') {
            $service = [
                'image' => $image,
                'environment' => [
                    'POSTGRES_DB' => $dbName,
                    'POSTGRES_USER' => $dbUser,
                    'POSTGRES_PASSWORD' => $dbPassword,
                ],
                'restart' => 'unless-stopped',
                'networks' => [$networkName],
            ];

            if ($environment === 'dev') {
                $service['volumes'] = ['./.dbdata:/var/lib/postgresql/data'];
                $service['ports'] = ["{$this->config['ports']['database']}:5432"];
            } else {
                $service['volumes'] = ['postgres_data:/var/lib/postgresql/data'];
                $service['ports'] = ["{$this->config['ports']['database']}:5432"];
            }
        } else {
            // MariaDB / MySQL
            $service = [
                'image' => $image,
                'container_name' => "{$this->config['project_name']}_db",
                'environment' => [
                    'MYSQL_ROOT_PASSWORD' => $dbPassword,
                    'MYSQL_DATABASE' => $dbName,
                    'MYSQL_USER' => $dbUser,
                    'MYSQL_PASSWORD' => $dbPassword,
                ],
                'restart' => 'unless-stopped',
                'networks' => [$networkName],
            ];

            if ($environment === 'dev') {
                $service['volumes'] = ['./.dbdata:/var/lib/mysql'];
                $service['ports'] = ["{$this->config['ports']['database']}:3306"];
            } else {
                $service['volumes'] = ['mariadb_data:/var/lib/mysql'];
                $service['ports'] = ["{$this->config['ports']['database']}:3306"];
            }
        }

        return $service;
    }

    protected function getDatabaseVolumeName(string $driver): string
    {
        return $driver === 'postgresql' ? 'postgres_data' : 'mariadb_data';
    }

    public function getDatabaseDriver(string $environment): string
    {
        return $this->selections[$environment]['database']['driver'] ?? 'mariadb';
    }
}
