<?php

namespace Microsomes\LaravelDevops\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Microsomes\LaravelDevops\Services\ComposeBuilder;
use Microsomes\LaravelDevops\Services\EnvGenerator;

class DockerInitCommand extends Command
{
    protected $signature = 'docker:init
                            {--force : Overwrite existing files}
                            {--dev-only : Only generate development environment}
                            {--prod-only : Only generate production environment}';

    protected $description = 'Scaffold Docker configuration for Laravel with dev/prod environments';

    protected Filesystem $files;
    protected array $config;
    protected array $selections = [];

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle(): int
    {
        $this->config = config('docker-scaffold');

        $this->info('🐳 Laravel DevOps Docker Scaffold');
        $this->newLine();

        // Gather selections via interactive prompts or use config defaults
        $this->gatherSelections();

        if (!$this->option('prod-only')) {
            $this->generateDevEnvironment();
        }

        if (!$this->option('dev-only')) {
            $this->generateProdEnvironment();
        }

        $this->generateGitignore();

        // Offer to update .env with correct values
        if (!$this->option('prod-only') && !$this->option('no-interaction')) {
            $this->offerEnvUpdate();
        }

        $this->newLine();
        $this->info('✅ Docker scaffold generated successfully!');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Review generated files in .docker/ directory');
        $this->line('  2. Run: docker compose up -d');
        $this->line('  3. For production, build images and push to registry');

        return Command::SUCCESS;
    }

    protected function gatherSelections(): void
    {
        if ($this->option('no-interaction')) {
            $this->selectionsFromConfig();
            return;
        }

        $this->gatherDevSelections();
        $this->gatherProdSelections();
    }

    protected function selectionsFromConfig(): void
    {
        // Dev selections from config
        $dbConfig = $this->config['services']['database'];
        $this->selections['dev'] = [
            'database' => [
                'enabled' => $dbConfig['enabled'] ?? true,
                'driver' => $dbConfig['driver'] ?? 'mariadb',
            ],
            'redis' => $this->config['services']['redis'] ?? true,
            'horizon' => $this->config['services']['horizon'] ?? true,
            'scheduler' => $this->config['services']['scheduler'] ?? true,
            'mailhog' => $this->config['services']['mailhog'] ?? true,
        ];

        // Prod selections from config
        $prodConfig = $this->config['production'] ?? [];
        $prodDbConfig = $prodConfig['database'] ?? [];
        $this->selections['prod'] = [
            'database' => [
                'enabled' => $prodDbConfig['enabled'] ?? true,
                'driver' => $prodDbConfig['driver'] ?? 'mariadb',
            ],
            'redis' => $prodConfig['redis'] ?? true,
            'horizon' => $prodConfig['horizon'] ?? true,
            'scheduler' => $prodConfig['scheduler'] ?? true,
            'migrate' => $prodConfig['migrate'] ?? true,
            'deploy_jobs' => $prodConfig['deploy_jobs'] ?? [],
            'deploy_services' => $prodConfig['deploy_services'] ?? [],
            'env_in_image' => $prodConfig['env_in_image'] ?? true,
        ];
    }

    protected function gatherDevSelections(): void
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('Development Environment');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        // Database driver selection
        $dbDriver = $this->choice(
            'Database driver',
            [
                'mariadb' => 'MariaDB (recommended)',
                'mysql' => 'MySQL 8',
                'postgresql' => 'PostgreSQL 16',
                'sqlite' => 'SQLite (no container, uses file)',
                'none' => 'None (I\'ll configure externally)',
            ],
            'mariadb'
        );

        $this->selections['dev']['database'] = [
            'enabled' => $dbDriver !== 'none',
            'driver' => $dbDriver,
        ];

        // Redis
        $this->selections['dev']['redis'] = $this->confirm('Include Redis?', true);

        // Horizon
        $this->selections['dev']['horizon'] = $this->confirm('Include Horizon queue worker?', true);

        // Scheduler
        $this->selections['dev']['scheduler'] = $this->confirm('Include Scheduler?', true);

        // Mailhog
        $this->selections['dev']['mailhog'] = $this->confirm('Include Mailhog for email testing?', true);

        $this->newLine();
    }

    protected function gatherProdSelections(): void
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('Production Environment');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        // Database driver selection (no sqlite for prod)
        $dbDriver = $this->choice(
            'Database in production stack',
            [
                'mariadb' => 'MariaDB (recommended)',
                'mysql' => 'MySQL 8',
                'postgresql' => 'PostgreSQL 16',
                'none' => 'None (external database)',
            ],
            'mariadb'
        );

        $this->selections['prod']['database'] = [
            'enabled' => $dbDriver !== 'none',
            'driver' => $dbDriver,
        ];

        // Redis
        $this->selections['prod']['redis'] = $this->confirm('Include Redis?', true);

        // Horizon
        $this->selections['prod']['horizon'] = $this->confirm('Include Horizon queue worker?', true);

        // Scheduler
        $this->selections['prod']['scheduler'] = $this->confirm('Include Scheduler?', true);

        // Migrate
        $this->selections['prod']['migrate'] = $this->confirm('Run migrations on deploy?', true);

        // Env in image
        $this->selections['prod']['env_in_image'] = $this->confirm('Copy .env.production into image?', true);

        // Deploy jobs (one-off commands)
        $deployJobsInput = $this->ask('One-off deploy commands (comma-separated, or empty)', '');
        $this->selections['prod']['deploy_jobs'] = $this->parseCommaSeparated($deployJobsInput);

        // Deploy services (persistent workers)
        $deployServicesInput = $this->ask('Persistent worker services (comma-separated, or empty)', '');
        $this->selections['prod']['deploy_services'] = $this->parseCommaSeparated($deployServicesInput);

        $this->newLine();
    }

    protected function parseCommaSeparated(string $input): array
    {
        if (empty(trim($input))) {
            return [];
        }

        return array_map('trim', explode(',', $input));
    }

    protected function generateDevEnvironment(): void
    {
        $this->comment('Generating development environment...');

        // Create directories
        $this->ensureDirectoryExists('.docker/dev/backend');
        $this->ensureDirectoryExists('.docker/dev/frontend/conf.d');
        $this->ensureDirectoryExists('.docker/dev/node');

        // Generate Dockerfiles with correct placeholders
        $this->generateFile('dev/backend/Dockerfile', '.docker/dev/backend/Dockerfile', 'dev');
        $this->generateFile('dev/frontend/Dockerfile', '.docker/dev/frontend/Dockerfile', 'dev');
        $this->generateFile('dev/frontend/conf.d/app.conf', '.docker/dev/frontend/conf.d/app.conf', 'dev');
        $this->generateFile('dev/node/Dockerfile', '.docker/dev/node/Dockerfile', 'dev');

        // Generate docker-compose.yml dynamically
        $this->generateDevCompose();

        // Create SQLite database file if needed
        if ($this->selections['dev']['database']['driver'] === 'sqlite') {
            $this->createSqliteDatabase();
        }

        $this->info('  ✓ Development environment');
    }

    protected function generateProdEnvironment(): void
    {
        $this->comment('Generating production environment...');

        // Create directories
        $this->ensureDirectoryExists('.docker/prod/backend');
        $this->ensureDirectoryExists('.docker/prod/frontend/conf.d');

        // Generate Dockerfiles with correct placeholders
        $this->generateFile('prod/backend/Dockerfile', '.docker/prod/backend/Dockerfile', 'prod');
        $this->generateFile('prod/frontend/Dockerfile', '.docker/prod/frontend/Dockerfile', 'prod');
        $this->generateFile('prod/frontend/conf.d/app.conf', '.docker/prod/frontend/conf.d/app.conf', 'prod');

        // Generate docker-compose.yml dynamically
        $this->generateProdCompose();

        // Generate .env.production template
        $this->generateProdEnv();

        $this->info('  ✓ Production environment');
    }

    protected function generateDevCompose(): void
    {
        $destinationPath = base_path('docker-compose.yml');

        if ($this->files->exists($destinationPath) && !$this->option('force')) {
            $this->warn("  ⚠ Skipped docker-compose.yml (already exists, use --force to overwrite)");
            return;
        }

        $builder = new ComposeBuilder($this->config, $this->selections);
        $content = $builder->buildDevCompose();

        $this->files->put($destinationPath, $content);
    }

    protected function generateProdCompose(): void
    {
        $destinationPath = base_path('.docker/prod/docker-compose.yml');

        if ($this->files->exists($destinationPath) && !$this->option('force')) {
            $this->warn("  ⚠ Skipped .docker/prod/docker-compose.yml (already exists, use --force to overwrite)");
            return;
        }

        $builder = new ComposeBuilder($this->config, $this->selections);
        $content = $builder->buildProdCompose();

        $this->files->put($destinationPath, $content);
    }

    protected function generateProdEnv(): void
    {
        $destinationPath = base_path('.docker/prod/.env.production');

        if ($this->files->exists($destinationPath) && !$this->option('force')) {
            $this->warn("  ⚠ Skipped .docker/prod/.env.production (already exists, use --force to overwrite)");
            return;
        }

        $generator = new EnvGenerator($this->config, $this->selections);
        $content = $generator->generateProdEnvTemplate();

        $this->files->put($destinationPath, $content);
    }

    protected function generateGitignore(): void
    {
        $gitignorePath = base_path('.docker/.gitignore');
        $content = "# Keep production secrets out of git\nprod/.env.production\n";

        if (!$this->files->exists($gitignorePath) || $this->option('force')) {
            $this->files->put($gitignorePath, $content);
            $this->info('  ✓ .docker/.gitignore');
        }
    }

    protected function generateFile(string $stub, string $destination, string $environment): void
    {
        $destinationPath = base_path($destination);

        if ($this->files->exists($destinationPath) && !$this->option('force')) {
            $this->warn("  ⚠ Skipped {$destination} (already exists, use --force to overwrite)");
            return;
        }

        $stubPath = __DIR__ . '/../Stubs/' . $stub . '.stub';
        $content = $this->files->get($stubPath);
        $content = $this->replacePlaceholders($content, $environment);

        $this->files->put($destinationPath, $content);
    }

    protected function ensureDirectoryExists(string $path): void
    {
        $fullPath = base_path($path);
        if (!$this->files->isDirectory($fullPath)) {
            $this->files->makeDirectory($fullPath, 0755, true);
        }
    }

    protected function replacePlaceholders(string $content, string $environment): string
    {
        $dbDriver = $this->selections[$environment]['database']['driver'] ?? 'mariadb';

        $replacements = [
            '{{PROJECT_NAME}}' => $this->config['project_name'],
            '{{NETWORK_NAME}}' => $this->config['network_name'],
            '{{PHP_VERSION}}' => $this->config['php_version'],
            '{{NODE_VERSION}}' => $this->config['node_version'],
            '{{PORT_FRONTEND}}' => $this->config['ports']['frontend'],
            '{{PORT_VITE}}' => $this->config['ports']['vite'],
            '{{PORT_DATABASE}}' => $this->config['ports']['database'],
            '{{PORT_REDIS}}' => $this->config['ports']['redis'],
            '{{PORT_MAILHOG_SMTP}}' => $this->config['ports']['mailhog_smtp'],
            '{{PORT_MAILHOG_WEB}}' => $this->config['ports']['mailhog_web'],
            '{{DB_IMAGE}}' => $this->getDatabaseImage($dbDriver),
            '{{DB_NAME}}' => $this->config['database']['name'],
            '{{DB_USER}}' => $this->config['database']['user'],
            '{{DB_PASSWORD}}' => $this->config['database']['password'],
            '{{REGISTRY_URL}}' => $this->config['registry']['url'],
            '{{REGISTRY_BACKEND}}' => $this->config['registry']['backend_image'],
            '{{REGISTRY_FRONTEND}}' => $this->config['registry']['frontend_image'],
            '{{TRAEFIK_DOMAIN}}' => $this->config['traefik']['domain'],
            '{{TRAEFIK_NETWORK}}' => $this->config['traefik']['network'],
            '{{TRAEFIK_CERTRESOLVER}}' => $this->config['traefik']['certresolver'],
            '{{DB_CLIENT_PACKAGES}}' => $this->getDbClientPackages($dbDriver),
            '{{DB_PHP_EXTENSIONS}}' => $this->getDbPhpExtensions($dbDriver),
            '{{ENV_COPY_COMMAND}}' => $this->getEnvCopyCommand($environment),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    protected function getDatabaseImage(string $driver): string
    {
        return $this->config['database_images'][$driver] ?? 'mariadb:latest';
    }

    protected function getDbClientPackages(string $driver): string
    {
        return match ($driver) {
            'postgresql' => 'libpq-dev',
            'mysql', 'mariadb' => 'mariadb-client',
            'sqlite', 'none' => '',
            default => 'mariadb-client',
        };
    }

    protected function getDbPhpExtensions(string $driver): string
    {
        return match ($driver) {
            'postgresql' => 'pdo_pgsql',
            'mysql', 'mariadb' => 'pdo_mysql',
            'sqlite' => 'pdo_sqlite',
            'none' => '',
            default => 'pdo_mysql',
        };
    }

    protected function getEnvCopyCommand(string $environment): string
    {
        if ($environment !== 'prod') {
            return '';
        }

        if (!($this->selections['prod']['env_in_image'] ?? true)) {
            return '';
        }

        return '# Copy production environment file into image' . "\n" .
               'COPY .docker/prod/.env.production /var/www/.env';
    }

    protected function createSqliteDatabase(): void
    {
        $dbPath = base_path('database/database.sqlite');

        if (!$this->files->exists($dbPath)) {
            $this->files->put($dbPath, '');
            $this->info('  ✓ Created database/database.sqlite');
        }
    }

    protected function offerEnvUpdate(): void
    {
        $this->newLine();
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('Environment Configuration');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        if (!$this->confirm('Would you like to update your .env file with Docker-compatible values?', true)) {
            return;
        }

        $envPath = base_path('.env');

        if (!$this->files->exists($envPath)) {
            $this->warn('  ⚠ No .env file found. Please create one first.');
            return;
        }

        $generator = new EnvGenerator($this->config, $this->selections);
        $values = $generator->getDevEnvValues();

        $envContent = $this->files->get($envPath);

        foreach ($values as $key => $value) {
            $pattern = "/^{$key}=.*/m";
            $replacement = $generator->formatEnvLine($key, $value);

            if (preg_match($pattern, $envContent)) {
                $envContent = preg_replace($pattern, $replacement, $envContent);
            } else {
                $envContent .= "\n{$replacement}";
            }
        }

        $this->files->put($envPath, $envContent);
        $this->info('  ✓ Updated .env with Docker-compatible values');

        $this->newLine();
        $this->line('Updated values:');
        foreach ($values as $key => $value) {
            $this->line("  {$key}={$value}");
        }
    }
}
