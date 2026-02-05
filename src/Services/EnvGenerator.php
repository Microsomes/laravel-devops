<?php

namespace Microsomes\LaravelDevops\Services;

class EnvGenerator
{
    protected array $config;
    protected array $selections;

    public function __construct(array $config, array $selections)
    {
        $this->config = $config;
        $this->selections = $selections;
    }

    public function getDevEnvValues(): array
    {
        $values = [];
        $dbConfig = $this->selections['dev']['database'];

        // Database configuration
        if ($dbConfig['enabled']) {
            $driver = $dbConfig['driver'];

            if ($driver === 'sqlite') {
                $values['DB_CONNECTION'] = 'sqlite';
                $values['DB_DATABASE'] = 'database/database.sqlite';
            } elseif ($driver !== 'none') {
                $values['DB_CONNECTION'] = $this->getDbConnection($driver);
                $values['DB_HOST'] = 'database';
                $values['DB_PORT'] = $this->getDbPort($driver);
                $values['DB_DATABASE'] = $this->config['database']['name'];
                $values['DB_USERNAME'] = $this->config['database']['user'];
                $values['DB_PASSWORD'] = $this->config['database']['password'];
            }
        }

        // Redis configuration
        if ($this->selections['dev']['redis']) {
            $values['REDIS_HOST'] = 'redis';
            $values['REDIS_PORT'] = '6379';
            $values['CACHE_DRIVER'] = 'redis';
            $values['SESSION_DRIVER'] = 'redis';
            $values['QUEUE_CONNECTION'] = 'redis';
        }

        // Mail configuration for Mailhog
        if ($this->selections['dev']['mailhog']) {
            $values['MAIL_MAILER'] = 'smtp';
            $values['MAIL_HOST'] = 'mailhog';
            $values['MAIL_PORT'] = '1025';
            $values['MAIL_USERNAME'] = 'null';
            $values['MAIL_PASSWORD'] = 'null';
            $values['MAIL_ENCRYPTION'] = 'null';
        }

        return $values;
    }

    public function generateProdEnvTemplate(): string
    {
        $dbConfig = $this->selections['prod']['database'];
        $lines = [];

        $lines[] = 'APP_NAME=Laravel';
        $lines[] = 'APP_ENV=production';
        $lines[] = 'APP_KEY=';
        $lines[] = 'APP_DEBUG=false';
        $lines[] = "APP_URL=https://{$this->config['traefik']['domain']}";
        $lines[] = '';

        // Database
        if ($dbConfig['enabled'] && $dbConfig['driver'] !== 'none') {
            $driver = $dbConfig['driver'];
            $lines[] = "DB_CONNECTION={$this->getDbConnection($driver)}";
            $lines[] = 'DB_HOST=database';
            $lines[] = "DB_PORT={$this->getDbPort($driver)}";
            $lines[] = "DB_DATABASE={$this->config['database']['name']}";
            $lines[] = "DB_USERNAME={$this->config['database']['user']}";
            $lines[] = "DB_PASSWORD={$this->config['database']['password']}";
        } else {
            $lines[] = '# External database - configure as needed';
            $lines[] = 'DB_CONNECTION=mysql';
            $lines[] = 'DB_HOST=';
            $lines[] = 'DB_PORT=3306';
            $lines[] = 'DB_DATABASE=';
            $lines[] = 'DB_USERNAME=';
            $lines[] = 'DB_PASSWORD=';
        }
        $lines[] = '';

        // Redis
        if ($this->selections['prod']['redis']) {
            $lines[] = 'REDIS_HOST=redis';
            $lines[] = 'REDIS_PORT=6379';
            $lines[] = 'CACHE_DRIVER=redis';
            $lines[] = 'SESSION_DRIVER=redis';
            $lines[] = 'QUEUE_CONNECTION=redis';
        } else {
            $lines[] = '# Redis not enabled - configure as needed';
            $lines[] = 'CACHE_DRIVER=file';
            $lines[] = 'SESSION_DRIVER=file';
            $lines[] = 'QUEUE_CONNECTION=sync';
        }
        $lines[] = '';

        // Common production settings
        $lines[] = 'LOG_CHANNEL=stack';
        $lines[] = 'LOG_LEVEL=error';
        $lines[] = '';
        $lines[] = 'MAIL_MAILER=smtp';
        $lines[] = 'MAIL_HOST=';
        $lines[] = 'MAIL_PORT=587';
        $lines[] = 'MAIL_USERNAME=';
        $lines[] = 'MAIL_PASSWORD=';
        $lines[] = 'MAIL_ENCRYPTION=tls';
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    public function formatEnvLine(string $key, string $value): string
    {
        // Quote values that contain spaces or special characters
        if (preg_match('/[\s#]/', $value) || $value === '') {
            return "{$key}=\"{$value}\"";
        }
        return "{$key}={$value}";
    }

    protected function getDbConnection(string $driver): string
    {
        return match ($driver) {
            'postgresql' => 'pgsql',
            'sqlite' => 'sqlite',
            'mysql', 'mariadb' => 'mysql',
            default => 'mysql',
        };
    }

    protected function getDbPort(string $driver): string
    {
        return match ($driver) {
            'postgresql' => '5432',
            default => '3306',
        };
    }
}
