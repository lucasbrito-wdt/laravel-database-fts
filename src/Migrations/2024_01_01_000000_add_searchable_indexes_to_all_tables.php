<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LucasBritoWdt\LaravelDatabaseFts\Drivers\DriverFactory;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Esta migration detecta automaticamente todas as models que usam o trait Searchable
     * e cria índices de busca usando o driver apropriado (PostgreSQL ou MySQL).
     *
     * @return void
     */
    public function up(): void
    {
        $connection = Schema::getConnection();
        $connectionName = $connection->getName();

        // Obtém o driver apropriado
        $driver = DriverFactory::make($connectionName);

        // Encontra todas as models que usam o trait Searchable
        $models = $this->findAllSearchableModels();

        foreach ($models as $modelClass) {
            try {
                $model = new $modelClass();
                $tableName = $model->getTable();
                $modelConnectionName = $model->getConnectionName();
                $reflection = new \ReflectionClass($modelClass);
                $searchableFields = $reflection->getStaticPropertyValue('searchable');

                if (empty($searchableFields) || !is_array($searchableFields)) {
                    continue;
                }

                // Obtém o driver para esta model (pode ser diferente se usar conexão diferente)
                $modelDriver = DriverFactory::make($modelConnectionName);

                // Gera nome do índice baseado no driver
                $driverName = $modelDriver->getDriverName();
                $indexName = $driverName === 'mysql'
                    ? "{$tableName}_search_ft_idx"
                    : "{$tableName}_search_trgm_idx";

                // Verifica se o índice já existe
                $indexExists = $this->indexExists($connection, $indexName, $tableName, $driverName);
                if ($indexExists) {
                    continue;
                }

                // Usa o driver para criar o índice
                $modelDriver->createIndex($tableName, $searchableFields, $indexName);
            } catch (\Exception $e) {
                // Ignora erros e continua com a próxima model
                continue;
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        $connection = Schema::getConnection();
        $connectionName = $connection->getName();

        // Encontra todas as models que usam o trait Searchable
        $models = $this->findAllSearchableModels();

        foreach ($models as $modelClass) {
            try {
                $model = new $modelClass();
                $tableName = $model->getTable();
                $modelConnectionName = $model->getConnectionName();

                // Obtém o driver para esta model
                $driver = DriverFactory::make($modelConnectionName);
                $driverName = $driver->getDriverName();

                // Gera nome do índice baseado no driver
                $indexName = $driverName === 'mysql'
                    ? "{$tableName}_search_ft_idx"
                    : "{$tableName}_search_trgm_idx";

                // Usa o driver para remover o índice
                $driver->dropIndex($tableName, $indexName);
            } catch (\Exception $e) {
                // Ignora erros e continua
                continue;
            }
        }
    }

    /**
     * Encontra todas as models que usam o trait Searchable.
     *
     * @return array
     */
    protected function findAllSearchableModels(): array
    {
        $models = [];
        $searchableTrait = 'LucasBritoWdt\\LaravelDatabaseFts\\Traits\\Searchable';

        // Namespaces para buscar
        $namespaces = [
            'App\\Models\\',
            'App\\',
        ];

        // Busca em estruturas customizadas: Domains/{domain}/Models/{model}
        $domainsPath = base_path('app/Domains');
        if (is_dir($domainsPath)) {
            $domainDirs = glob($domainsPath . '/*', GLOB_ONLYDIR);
            foreach ($domainDirs as $domainDir) {
                $domainName = basename($domainDir);
                $modelsPath = $domainDir . '/Models';

                if (is_dir($modelsPath)) {
                    $namespace = "App\\Domains\\{$domainName}\\Models\\";
                    $namespaces[] = $namespace;
                }
            }
        }

        // Busca em cada namespace
        foreach ($namespaces as $namespace) {
            $models = array_merge($models, $this->findModelsInNamespace($namespace, $searchableTrait));
        }

        return array_unique($models);
    }

    /**
     * Busca models em um namespace específico.
     *
     * @param string $namespace
     * @param string $searchableTrait
     * @return array
     */
    protected function findModelsInNamespace(string $namespace, string $searchableTrait): array
    {
        $models = [];

        // Converte namespace para caminho de arquivo
        $relativePath = str_replace('App\\', 'app/', $namespace);
        $modelsPath = base_path(str_replace('\\', '/', $relativePath));

        if (!is_dir($modelsPath)) {
            return $models;
        }

        $files = glob($modelsPath . '/*.php');

        foreach ($files as $file) {
            $className = $namespace . basename($file, '.php');

            if (!class_exists($className)) {
                continue;
            }

            // Verifica se é uma subclasse de Model
            if (!is_subclass_of($className, \Illuminate\Database\Eloquent\Model::class)) {
                continue;
            }

            // Verifica se usa a trait Searchable
            $traits = $this->getClassTraits($className);
            if (!in_array($searchableTrait, $traits)) {
                continue;
            }

            // Verifica se tem o array $searchable definido
            if (!property_exists($className, 'searchable')) {
                continue;
            }

            try {
                $reflection = new \ReflectionClass($className);
                $searchableFields = $reflection->getStaticPropertyValue('searchable');

                if (empty($searchableFields) || !is_array($searchableFields)) {
                    continue;
                }

                $models[] = $className;
            } catch (\Exception $e) {
                continue;
            }
        }

        return $models;
    }

    /**
     * Obtém todas as traits usadas por uma classe (incluindo traits de traits).
     *
     * @param string $class
     * @param array $processed
     * @return array
     */
    protected function getClassTraits(string $class, array &$processed = []): array
    {
        if (in_array($class, $processed)) {
            return [];
        }

        $processed[] = $class;
        $traits = [];

        // Obtém traits da classe atual e suas classes pai
        do {
            $classTraits = class_uses($class) ?: [];
            $traits = array_merge($traits, $classTraits);
        } while ($class = get_parent_class($class));

        // Obtém traits das traits recursivamente
        foreach ($traits as $trait) {
            $traitTraits = $this->getClassTraits($trait, $processed);
            $traits = array_merge($traits, $traitTraits);
        }

        return array_unique($traits);
    }

    /**
     * Verifica se um índice já existe.
     *
     * @param \Illuminate\Database\Connection $connection
     * @param string $indexName
     * @param string $tableName
     * @param string $driverName
     * @return bool
     */
    protected function indexExists($connection, string $indexName, string $tableName, string $driverName): bool
    {
        try {
            if ($driverName === 'mysql') {
                // MySQL: verifica na tabela information_schema.statistics
                $result = $connection->selectOne("
                    SELECT COUNT(*) as count
                    FROM information_schema.statistics
                    WHERE table_schema = DATABASE()
                    AND table_name = ?
                    AND index_name = ?
                ", [$tableName, $indexName]);

                return ($result->count ?? 0) > 0;
            } else {
                // PostgreSQL: verifica na tabela pg_indexes
                $result = $connection->selectOne("
                    SELECT EXISTS (
                        SELECT 1
                        FROM pg_indexes
                        WHERE indexname = ? AND tablename = ?
                    ) as exists
                ", [$indexName, $tableName]);

                return $result->exists ?? false;
            }
        } catch (\Exception $e) {
            return false;
        }
    }
};
