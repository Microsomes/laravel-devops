<?php

namespace Microsomes\LaravelDevops\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class DockerInitCommand extends Command
{
    protected $signature = 'docker:init
                            {--force : Overwrite existing files}
                            {--dev-only : Only generate development environment}
                            {--prod-only : Only generate production environment}';

    protected $description = 'Scaffold Docker configuration for Laravel with dev/prod environments';

    protected Filesystem $files;
    protected array $config;

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

        if (!$this->option('prod-only')) {
            $this->generateDevEnvironment();
        }

        if (!$this->option('dev-only')) {
            $this->generateProdEnvironment();
        }

        $this->generateGitignore();

        $this->newLine();
        $this->info('✅ Docker scaffold generated successfully!');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Review and customize config/docker-scaffold.php');
        $this->line('  2. Run: docker compose up -d');
        $this->line('  3. For production, build images and push to registry');

        return Command::SUCCESS;
    }

    protected function generateDevEnvironment(): void
    {
        $this->comment('Generating development environment...');

        // Create directories
        $this->ensureDirectoryExists('.docker/dev/backend');
        $this->ensureDirectoryExists('.docker/dev/frontend/conf.d');
        $this->ensureDirectoryExists('.docker/dev/node');

        // Generate files
        $this->generateFile('dev/backend/Dockerfile', '.docker/dev/backend/Dockerfile');
        $this->generateFile('dev/frontend/Dockerfile', '.docker/dev/frontend/Dockerfile');
        $this->generateFile('dev/frontend/conf.d/app.conf', '.docker/dev/frontend/conf.d/app.conf');
        $this->generateFile('dev/node/Dockerfile', '.docker/dev/node/Dockerfile');
        $this->generateFile('docker-compose.yml', 'docker-compose.yml');

        $this->info('  ✓ Development environment');
    }

    protected function generateProdEnvironment(): void
    {
        $this->comment('Generating production environment...');

        // Create directories
        $this->ensureDirectoryExists('.docker/prod/backend');
        $this->ensureDirectoryExists('.docker/prod/frontend/conf.d');

        // Generate files
        $this->generateFile('prod/backend/Dockerfile', '.docker/prod/backend/Dockerfile');
        $this->generateFile('prod/frontend/Dockerfile', '.docker/prod/frontend/Dockerfile');
        $this->generateFile('prod/frontend/conf.d/app.conf', '.docker/prod/frontend/conf.d/app.conf');
        $this->generateFile('prod/docker-compose.yml', '.docker/prod/docker-compose.yml');
        $this->generateFile('prod/.env.production', '.docker/prod/.env.production');

        $this->info('  ✓ Production environment');
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

    protected function generateFile(string $stub, string $destination): void
    {
        $destinationPath = base_path($destination);

        if ($this->files->exists($destinationPath) && !$this->option('force')) {
            $this->warn("  ⚠ Skipped {$destination} (already exists, use --force to overwrite)");
            return;
        }

        $stubPath = __DIR__ . '/../Stubs/' . $stub . '.stub';
        $content = $this->files->get($stubPath);
        $content = $this->replacePlaceholders($content);

        $this->files->put($destinationPath, $content);
    }

    protected function ensureDirectoryExists(string $path): void
    {
        $fullPath = base_path($path);
        if (!$this->files->isDirectory($fullPath)) {
            $this->files->makeDirectory($fullPath, 0755, true);
        }
    }

    protected function replacePlaceholders(string $content): string
    {
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
            '{{DB_IMAGE}}' => $this->config['database']['image'],
            '{{DB_NAME}}' => $this->config['database']['name'],
            '{{DB_USER}}' => $this->config['database']['user'],
            '{{DB_PASSWORD}}' => $this->config['database']['password'],
            '{{REGISTRY_URL}}' => $this->config['registry']['url'],
            '{{REGISTRY_BACKEND}}' => $this->config['registry']['backend_image'],
            '{{REGISTRY_FRONTEND}}' => $this->config['registry']['frontend_image'],
            '{{TRAEFIK_DOMAIN}}' => $this->config['traefik']['domain'],
            '{{TRAEFIK_NETWORK}}' => $this->config['traefik']['network'],
            '{{TRAEFIK_CERTRESOLVER}}' => $this->config['traefik']['certresolver'],
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }
}
