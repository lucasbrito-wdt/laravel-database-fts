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
        // Proteção: só executa se estiver em contexto de migration
        // A migration só deve executar quando php artisan migrate for chamado explicitamente
        if (app()->runningInConsole()) {
            $command = $_SERVER['argv'][1] ?? '';
            $allowedCommands = ['migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:rollback', 'migrate:reset', 'migrate:status'];
            if (!in_array($command, $allowedCommands, true)) {
                return;
            }
        } elseif (!app()->runningUnitTests()) {
            // Se não estiver em console nem em testes, não executa
            return;
        }

        try {
            $connection = Schema::getConnection();
            $connectionName = $connection->getName();

            // Obtém o driver apropriado
            $driver = DriverFactory::make($connectionName);

            // Encontra todas as models que usam o trait Searchable
            $models = $this->findAllSearchableModels();

            // Limita o número de models processadas para evitar esgotamento de memória
            if (count($models) > 100) {
                throw new \RuntimeException('Muitas models encontradas (' . count($models) . '). Limite o número de models ou processe em lotes.');
            }

            foreach ($models as $modelClass) {
                try {
                    // Usa Reflection em vez de instanciar para evitar autoload excessivo
                    $reflection = new \ReflectionClass($modelClass);

                    // Verifica se tem o array $searchable antes de instanciar
                    if (!$reflection->hasProperty('searchable')) {
                        continue;
                    }

                    $searchableFields = $reflection->getStaticPropertyValue('searchable');

                    if (empty($searchableFields) || !is_array($searchableFields)) {
                        continue;
                    }

                    // Obtém nome da tabela sem instanciar o model
                    $tableName = $this->getTableNameFromModel($reflection);

                    // Obtém conexão do model sem instanciar
                    $modelConnectionName = $this->getConnectionNameFromModel($reflection, $connectionName);

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
        } catch (\Throwable $e) {
            // Captura qualquer erro (incluindo erros de memória) e loga
            if (function_exists('logger')) {
                logger()->error('Erro ao executar migration de busca: ' . $e->getMessage());
            }
            throw $e;
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
                // Usa Reflection em vez de instanciar para evitar autoload excessivo
                $reflection = new \ReflectionClass($modelClass);

                // Obtém nome da tabela sem instanciar o model
                $tableName = $this->getTableNameFromModel($reflection);

                // Obtém conexão do model sem instanciar
                $modelConnectionName = $this->getConnectionNameFromModel($reflection, $connectionName);

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

            try {
                // Usa Reflection em vez de class_exists para evitar autoload que pode executar boot methods
                if (!file_exists($file)) {
                    continue;
                }

                // Tenta carregar a classe sem executar boot methods
                // Primeiro verifica se a classe existe sem autoload
                if (!class_exists($className, false)) {
                    // Se não existir, tenta autoload mas com proteção
                    try {
                        if (!class_exists($className)) {
                            continue;
                        }
                    } catch (\Throwable $e) {
                        // Ignora erros de autoload
                        continue;
                    }
                }

                $reflection = new \ReflectionClass($className);

                // Verifica se é uma subclasse de Model sem instanciar
                if (!$reflection->isSubclassOf(\Illuminate\Database\Eloquent\Model::class)) {
                    continue;
                }

                // Verifica se usa a trait Searchable sem executar boot methods
                $traits = $this->getClassTraits($className);
                if (!in_array($searchableTrait, $traits)) {
                    continue;
                }

                // Verifica se tem o array $searchable definido
                if (!$reflection->hasProperty('searchable')) {
                    continue;
                }

                // Obtém os campos sem instanciar o model
                $searchableFields = $reflection->getStaticPropertyValue('searchable');

                if (empty($searchableFields) || !is_array($searchableFields)) {
                    continue;
                }

                $models[] = $className;
            } catch (\Throwable $e) {
                // Ignora qualquer erro (autoload, boot methods, etc)
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
     * @param int $depth Limite de profundidade para evitar loops infinitos
     * @return array
     */
    protected function getClassTraits(string $class, array &$processed = [], int $depth = 0): array
    {
        // Proteção contra loops infinitos
        if ($depth > 50) {
            return [];
        }

        // Proteção contra recursão circular
        if (in_array($class, $processed, true)) {
            return [];
        }

        $processed[] = $class;
        $traits = [];

        // Salva a classe original para não modificar o parâmetro
        $currentClass = $class;

        // Obtém traits da classe atual e suas classes pai
        do {
            $classTraits = class_uses($currentClass, false) ?: [];
            $traits = array_merge($traits, $classTraits);
            $currentClass = get_parent_class($currentClass);
        } while ($currentClass !== false);

        // Obtém traits das traits recursivamente
        foreach ($traits as $trait) {
            // Verifica novamente para evitar processar traits já processadas
            if (!in_array($trait, $processed, true)) {
                $traitTraits = $this->getClassTraits($trait, $processed, $depth + 1);
                $traits = array_merge($traits, $traitTraits);
            }
        }

        return array_unique($traits);
    }

    /**
     * Obtém nome da tabela do model usando Reflection.
     *
     * @param \ReflectionClass $reflection
     * @return string
     */
    protected function getTableNameFromModel(\ReflectionClass $reflection): string
    {
        // Tenta obter o nome da tabela sem instanciar o model
        if ($reflection->hasProperty('table')) {
            $tableProperty = $reflection->getProperty('table');
            $tableProperty->setAccessible(true);
            $tableName = $tableProperty->getValue();
            if ($tableName) {
                return $tableName;
            }
        }

        // Se não encontrar, usa o nome da classe convertido para snake_case
        $className = $reflection->getShortName();
        return Str::snake(Str::pluralStudly($className));
    }

    /**
     * Obtém nome da conexão do model usando Reflection.
     *
     * @param \ReflectionClass $reflection
     * @param string $defaultConnection
     * @return string
     */
    protected function getConnectionNameFromModel(\ReflectionClass $reflection, string $defaultConnection): string
    {
        // Tenta obter a conexão sem instanciar o model
        if ($reflection->hasProperty('connection')) {
            $connectionProperty = $reflection->getProperty('connection');
            $connectionProperty->setAccessible(true);
            $connectionName = $connectionProperty->getValue();
            if ($connectionName) {
                return $connectionName;
            }
        }

        return $defaultConnection;
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
