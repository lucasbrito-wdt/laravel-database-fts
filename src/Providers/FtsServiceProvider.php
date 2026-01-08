<?php

namespace LucasBritoWdt\LaravelDatabaseFts\Providers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use LucasBritoWdt\LaravelDatabaseFts\Commands\MakeSearchableCommand;
use LucasBritoWdt\LaravelDatabaseFts\Drivers\DriverFactory;

class FtsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/fts.php',
            'fts'
        );

        $this->app->singleton(\LucasBritoWdt\LaravelDatabaseFts\Services\SearchService::class, function ($app) {
            return new \LucasBritoWdt\LaravelDatabaseFts\Services\SearchService();
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Registra comandos Artisan
        $this->registerCommands();

        // Publica arquivos de configuração
        $this->publishConfig();

        // Carrega migrations de estruturas customizadas automaticamente
        $this->loadCustomMigrations();

        // Carrega migration consolidada do pacote que detecta todas as models automaticamente
        $this->loadMigrationsFrom(__DIR__ . '/../Migrations');

        // Registra macro customizada no Blueprint para uso em Schema::create()
        $this->registerBlueprintMacros();
    }

    /**
     * Registra comandos Artisan.
     *
     * @return void
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeSearchableCommand::class,
            ]);
        }
    }

    /**
     * Publica arquivos de configuração.
     *
     * Publica o arquivo de configuração para o diretório config/ da aplicação.
     * 
     * Uso:
     *   php artisan vendor:publish --tag=fts-config
     *   php artisan vendor:publish --tag=laravel-database-fts-config
     *
     * @return void
     */
    protected function publishConfig(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/fts.php' => $this->app->configPath('fts.php'),
            ], ['fts-config', 'laravel-database-fts-config']);
        }
    }

    /**
     * Registra macros customizadas no Blueprint.
     *
     * @return void
     */
    protected function registerBlueprintMacros(): void
    {
        Blueprint::macro('searchableIndex', function (array $fields, ?string $indexName = null) {
            $tableName = $this->getTable();
            $connection = Schema::getConnection();
            $connectionName = $connection->getName();

            // Verifica se a tabela já existe
            $schemaBuilder = $connection->getSchemaBuilder();

            // Se a tabela já existe (Schema::table), executa imediatamente
            if ($schemaBuilder->hasTable($tableName)) {
                // Obtém o driver apropriado
                $driver = DriverFactory::make($connectionName);

                // Usa o driver para criar o índice
                $driver->createIndex($tableName, $fields, $indexName);
            } else {
                // Se a tabela não existe (Schema::create), não podemos criar o índice ainda
                // O índice deve ser criado após o Schema::create() terminar
                // 
                // SOLUÇÃO: Crie o índice após o Schema::create() terminar usando Schema::table():
                //
                // Schema::create('users', function (Blueprint $table) {
                //     $table->id();
                //     $table->string('name');
                //     $table->string('email');
                // });
                //
                // Schema::table('users', function (Blueprint $table) {
                //     $table->searchableIndex(['name', 'email']);
                // });
                //
                // Ou use uma migration separada para criar o índice.

                // Por enquanto, não fazemos nada aqui
                // O usuário precisa criar o índice manualmente após o Schema::create() terminar
            }

            return $this;
        });
    }

    /**
     * Carrega migrations de estruturas customizadas (ex: Domains/{domain}/Migrations).
     *
     * @return void
     */
    protected function loadCustomMigrations(): void
    {
        $migrationPaths = $this->getCustomMigrationPaths();

        foreach ($migrationPaths as $path) {
            if (is_dir($path)) {
                $this->loadMigrationsFrom($path);
            }
        }
    }

    /**
     * Obtém caminhos de migrations customizadas.
     *
     * @return array
     */
    protected function getCustomMigrationPaths(): array
    {
        $paths = [];

        // Busca migrations em estruturas Domains/*/Migrations
        $domainsPath = base_path('app/Domains');
        if (is_dir($domainsPath)) {
            $domainDirs = glob($domainsPath . '/*', GLOB_ONLYDIR);
            foreach ($domainDirs as $domainDir) {
                $migrationsPath = $domainDir . DIRECTORY_SEPARATOR . 'Migrations';
                if (is_dir($migrationsPath)) {
                    $paths[] = $migrationsPath;
                }
            }
        }

        return $paths;
    }
}
