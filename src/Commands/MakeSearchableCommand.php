<?php

namespace LucasBritoWdt\LaravelDatabaseFts\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Class MakeSearchableCommand
 *
 * Comando Artisan para gerar migrations de busca por similaridade automaticamente.
 *
 * @package LucasBritoWdt\LaravelDatabaseFts\Commands
 */
class MakeSearchableCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:searchable 
                            {model? : Nome do model (ex: Post). Omita para gerar para todas as models}
                            {--all : Gera migrations para todas as models que usam o trait Searchable}
                            {--single : Gera uma única migration consolidada para todas as models}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gera migration para adicionar busca por similaridade a um model ou todas as models';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $modelName = $this->argument('model');
        $all = $this->option('all') || empty($modelName);
        $single = $this->option('single');

        if ($single) {
            return $this->handleSingle();
        }

        if ($all) {
            return $this->handleAll();
        }

        // Tenta encontrar a classe do model
        $modelClass = $this->getModelClass($modelName);

        if (!$modelClass || !class_exists($modelClass)) {
            $this->error("Model {$modelName} não encontrado.");
            $this->line("Tente usar o namespace completo, ex: App\\Models\\{$modelName}");
            return Command::FAILURE;
        }

        // Verifica se a model já usa a trait Searchable
        $traits = $this->getClassTraits($modelClass);
        $searchableTrait = 'LucasBritoWdt\\LaravelDatabaseFts\\Traits\\Searchable';
        $hasSearchableTrait = in_array($searchableTrait, $traits);

        if (!$hasSearchableTrait) {
            $this->warn("O model {$modelName} não usa o trait Searchable.");
            if ($this->confirm('Deseja adicionar o trait Searchable ao model?', true)) {
                $this->updateModel($modelName, []);
            } else {
                $this->error("A migration precisa que o model use o trait Searchable e defina o array \$searchable.");
                return Command::FAILURE;
            }
        }

        // Verifica se tem o array $searchable definido
        if (property_exists($modelClass, 'searchable')) {
            $reflection = new \ReflectionClass($modelClass);
            $searchableFields = $reflection->getStaticPropertyValue('searchable');

            if (empty($searchableFields) || !is_array($searchableFields)) {
                $this->error("O model {$modelName} não define o array \$searchable ou está vazio.");
                $this->line("Adicione: protected static array \$searchable = ['campo1', 'campo2'];");
                return Command::FAILURE;
            }

            $this->info("Campos encontrados: " . implode(', ', $searchableFields));
        } else {
            $this->error("O model {$modelName} não define o array \$searchable.");
            $this->line("Adicione: protected static array \$searchable = ['campo1', 'campo2'];");
            return Command::FAILURE;
        }

        // Obtém nome da tabela
        $tableName = $this->getTableName($modelName);

        // Gera migration (sem campos, pois será lido automaticamente)
        $migrationName = "add_searchable_index_to_{$tableName}_table";
        $migrationPath = $this->createMigration($migrationName, $tableName, $modelClass);

        $this->info("Migration criada: {$migrationPath}");
        $this->line("A migration lerá automaticamente os campos do array \$searchable da model.");

        return Command::SUCCESS;
    }

    /**
     * Gera migrations para todas as models que usam o trait Searchable.
     *
     * @return int
     */
    protected function handleAll(): int
    {
        $this->info('Buscando todas as models que usam o trait Searchable...');
        $this->line('');

        $models = $this->findAllSearchableModels();

        if (empty($models)) {
            $this->warn('Nenhuma model encontrada que use o trait Searchable.');
            $this->line('Certifique-se de que suas models:');
            $this->line('  1. Usam o trait LucasBritoWdt\\LaravelDatabaseFts\\Traits\\Searchable');
            $this->line('  2. Definem o array protected static $searchable');
            return Command::SUCCESS;
        }

        $this->info("Encontradas " . count($models) . " model(s):");
        foreach ($models as $modelClass) {
            $this->line("  - {$modelClass}");
        }
        $this->line('');

        if (!$this->confirm('Deseja gerar migrations para todas essas models?', true)) {
            $this->info('Operação cancelada.');
            return Command::SUCCESS;
        }

        $successCount = 0;
        $errorCount = 0;

        foreach ($models as $modelClass) {
            try {
                $model = new $modelClass();
                $tableName = $model->getTable();
                $modelName = class_basename($modelClass);

                // Verifica se já existe migration para esta tabela
                $migrationName = "add_searchable_index_to_{$tableName}_table";
                $existingMigration = $this->findExistingMigration($migrationName);

                if ($existingMigration) {
                    $this->warn("  ⚠️  {$modelName}: Migration já existe ({$existingMigration})");
                    continue;
                }

                // Gera migration
                $migrationPath = $this->createMigration($migrationName, $tableName, $modelClass);
                $this->info("  ✅ {$modelName}: Migration criada");
                $successCount++;
            } catch (\Exception $e) {
                $this->error("  ❌ {$modelClass}: {$e->getMessage()}");
                $errorCount++;
            }
        }

        $this->line('');
        $this->info("Concluído! {$successCount} migration(s) criada(s), {$errorCount} erro(s).");

        return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Gera uma única migration consolidada para todas as models.
     *
     * @return int
     */
    protected function handleSingle(): int
    {
        $this->info('Buscando todas as models que usam o trait Searchable...');
        $this->line('');

        $models = $this->findAllSearchableModels();

        if (empty($models)) {
            $this->warn('Nenhuma model encontrada que use o trait Searchable.');
            $this->line('Certifique-se de que suas models:');
            $this->line('  1. Usam o trait LucasBritoWdt\\LaravelDatabaseFts\\Traits\\Searchable');
            $this->line('  2. Definem o array protected static $searchable');
            return Command::SUCCESS;
        }

        $this->info("Encontradas " . count($models) . " model(s):");
        foreach ($models as $modelClass) {
            try {
                $model = new $modelClass();
                $tableName = $model->getTable();
                $reflection = new \ReflectionClass($modelClass);
                $searchableFields = $reflection->getStaticPropertyValue('searchable');
                $this->line("  - {$modelClass} ({$tableName}): " . implode(', ', $searchableFields));
            } catch (\Exception $e) {
                $this->line("  - {$modelClass} (erro ao obter informações)");
            }
        }
        $this->line('');

        // Verifica se já existe migration consolidada
        $migrationName = "add_searchable_indexes_to_all_tables";
        $existingMigration = $this->findExistingMigration($migrationName);

        if ($existingMigration) {
            if (!$this->confirm('Já existe uma migration consolidada. Deseja criar uma nova?', false)) {
                $this->info('Operação cancelada.');
                return Command::SUCCESS;
            }
        }

        // Gera migration consolidada
        $migrationPath = $this->createConsolidatedMigration($models);

        $this->info("Migration consolidada criada: {$migrationPath}");
        $this->line("Esta migration criará índices para todas as " . count($models) . " model(s) encontradas.");

        return Command::SUCCESS;
    }

    /**
     * Cria uma migration consolidada para todas as models.
     *
     * @param array $models
     * @return string
     */
    protected function createConsolidatedMigration(array $models): string
    {
        $timestamp = date('Y_m_d_His');
        $fileName = "{$timestamp}_add_searchable_indexes_to_all_tables.php";
        $migrationsPath = base_path('database/migrations');
        $filePath = "{$migrationsPath}/{$fileName}";

        $content = $this->generateConsolidatedMigrationContent($models);

        File::put($filePath, $content);

        return $filePath;
    }

    /**
     * Gera o conteúdo da migration consolidada.
     *
     * @param array $models
     * @return string
     */
    protected function generateConsolidatedMigrationContent(array $models): string
    {
        $upStatements = [];
        $downStatements = [];

        // Detecta o driver baseado na primeira model (assumindo que todas usam a mesma conexão)
        $driverName = 'postgres'; // padrão
        try {
            if (!empty($models)) {
                $firstModel = new $models[0]();
                $connectionName = $firstModel->getConnectionName();
                $driver = \LucasBritoWdt\LaravelDatabaseFts\Drivers\DriverFactory::make($connectionName);
                $driverName = $driver->getDriverName();
            }
        } catch (\Exception $e) {
            // Mantém padrão se houver erro
        }

        // Adiciona código específico do driver
        if ($driverName === 'postgres') {
            $upStatements[] = "        // Cria extensão pg_trgm (apenas uma vez)";
            $upStatements[] = "        \$connection->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm;');";
            $upStatements[] = "";
        }

        foreach ($models as $modelClass) {
            try {
                $model = new $modelClass();
                $tableName = $model->getTable();
                $connectionName = $model->getConnectionName();
                $reflection = new \ReflectionClass($modelClass);
                $searchableFields = $reflection->getStaticPropertyValue('searchable');

                if (empty($searchableFields) || !is_array($searchableFields)) {
                    continue;
                }

                // Obtém o driver para esta model
                $driver = \LucasBritoWdt\LaravelDatabaseFts\Drivers\DriverFactory::make($connectionName);

                // Gera nome do índice baseado no driver
                $indexName = $driverName === 'mysql'
                    ? "{$tableName}_search_ft_idx"
                    : "{$tableName}_search_trgm_idx";

                $upStatements[] = "        // Índice para {$tableName}";

                if ($driverName === 'mysql') {
                    // MySQL FULLTEXT index
                    $columnsList = implode(', ', $searchableFields);
                    $upStatements[] = "        \$connection->statement(\"";
                    $upStatements[] = "            CREATE FULLTEXT INDEX {$indexName}";
                    $upStatements[] = "            ON {$tableName} ({$columnsList})";
                    $upStatements[] = "        \");";
                } else {
                    // PostgreSQL GIN index
                    $expressionParts = [];
                    foreach ($searchableFields as $field) {
                        $expressionParts[] = "COALESCE({$field}::text, '')";
                    }
                    $expression = '(' . implode(" || ' ' || ", $expressionParts) . ')';
                    $upStatements[] = "        \$connection->statement(\"";
                    $upStatements[] = "            CREATE INDEX IF NOT EXISTS {$indexName}";
                    $upStatements[] = "            ON {$tableName}";
                    $upStatements[] = "            USING GIN ({$expression} gin_trgm_ops);";
                    $upStatements[] = "        \");";
                }
                $upStatements[] = "";

                if ($driverName === 'mysql') {
                    $downStatements[] = "        \$connection->statement('DROP INDEX {$indexName} ON {$tableName};');";
                } else {
                    $downStatements[] = "        \$connection->statement('DROP INDEX IF EXISTS {$indexName};');";
                }
            } catch (\Exception $e) {
                // Ignora erros e continua
                continue;
            }
        }

        $upCode = implode("\n", $upStatements);
        $downCode = implode("\n", $downStatements);

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        \$connection = Schema::getConnection();

{$upCode}
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        \$connection = Schema::getConnection();

{$downCode}
    }
};
PHP;
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
     * Verifica se já existe uma migration para a tabela.
     *
     * @param string $migrationName
     * @return string|null
     */
    protected function findExistingMigration(string $migrationName): ?string
    {
        $migrationsPath = base_path('database/migrations');

        if (!is_dir($migrationsPath)) {
            return null;
        }

        $files = glob($migrationsPath . '/*.php');

        foreach ($files as $file) {
            if (str_contains($file, $migrationName)) {
                return basename($file);
            }
        }

        return null;
    }

    /**
     * Obtém nome da tabela do model.
     *
     * @param string $modelName
     * @return string
     */
    protected function getTableName(string $modelName): string
    {
        // Tenta encontrar o model
        $modelClass = $this->getModelClass($modelName);

        if ($modelClass && class_exists($modelClass)) {
            $model = new $modelClass();
            return $model->getTable();
        }

        // Fallback: pluraliza e snake_case
        return Str::snake(Str::plural($modelName));
    }

    /**
     * Tenta encontrar a classe do model.
     * Busca em namespaces padrão e estruturas customizadas (ex: Domains/{domain}/Models/{model}).
     *
     * @param string $modelName
     * @return string|null
     */
    protected function getModelClass(string $modelName): ?string
    {
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

        foreach ($namespaces as $namespace) {
            $class = $namespace . $modelName;
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Cria migration.
     *
     * @param string $migrationName
     * @param string $tableName
     * @param string $modelClass
     * @return string
     */
    protected function createMigration(
        string $migrationName,
        string $tableName,
        string $modelClass
    ): string {
        $timestamp = date('Y_m_d_His');
        $fileName = "{$timestamp}_{$migrationName}.php";
        $migrationsPath = base_path('database/migrations');
        $filePath = "{$migrationsPath}/{$fileName}";

        $stubPath = __DIR__ . '/../Migrations/searchable.stub.php';

        if (!File::exists($stubPath)) {
            $this->error("Stub não encontrado: {$stubPath}");
            return Command::FAILURE;
        }

        $stub = File::get($stubPath);
        $content = $this->replaceStubPlaceholders($stub, $tableName);

        File::put($filePath, $content);

        return $filePath;
    }

    /**
     * Substitui placeholders no stub.
     *
     * @param string $stub
     * @param string $tableName
     * @return string
     */
    protected function replaceStubPlaceholders(
        string $stub,
        string $tableName
    ): string {
        // Nome da tabela
        $stub = str_replace('{{TABLE}}', $tableName, $stub);

        return $stub;
    }

    /**
     * Atualiza model para usar trait Searchable.
     *
     * @param string $modelName
     * @param array $fields
     * @return void
     */
    protected function updateModel(string $modelName, array $fields = []): void
    {
        $modelClass = $this->getModelClass($modelName);

        if (!$modelClass || !class_exists($modelClass)) {
            $this->warn("Model {$modelName} não encontrado. Atualize manualmente.");
            $this->displayModelExample($modelName, $fields);
            return;
        }

        $modelPath = $this->getModelPath($modelClass);

        if (!File::exists($modelPath)) {
            $this->warn("Arquivo do model não encontrado: {$modelPath}");
            $this->displayModelExample($modelName, $fields);
            return;
        }

        $content = File::get($modelPath);

        // Adiciona use statement se não existir
        if (!str_contains($content, 'use LucasBritoWdt\\LaravelDatabaseFts\\Traits\\Searchable;')) {
            $content = str_replace(
                'use Illuminate\\Database\\Eloquent\\Model;',
                "use Illuminate\\Database\\Eloquent\\Model;\nuse LucasBritoWdt\\LaravelDatabaseFts\\Traits\\Searchable;",
                $content
            );
        }

        // Adiciona trait se não existir
        if (!str_contains($content, 'use Searchable;')) {
            $classDeclaration = "class {$modelName} extends Model";
            $content = str_replace(
                $classDeclaration,
                $classDeclaration . "\n{\n    use Searchable;",
                $content
            );
        }

        // Não adiciona $searchable automaticamente - usuário deve definir manualmente
        if (!str_contains($content, 'protected static array $searchable')) {
            $this->warn("Lembre-se de adicionar o array \$searchable ao model:");
            $this->line("    protected static array \$searchable = ['campo1', 'campo2'];");
        }

        File::put($modelPath, $content);
        $this->info("Model atualizado: {$modelPath}");
    }

    /**
     * Obtém caminho do arquivo do model.
     *
     * @param string $modelClass
     * @return string
     */
    protected function getModelPath(string $modelClass): string
    {
        $reflection = new \ReflectionClass($modelClass);
        return $reflection->getFileName();
    }

    /**
     * Exibe exemplo de como atualizar o model manualmente.
     *
     * @param string $modelName
     * @param array $fields
     * @return void
     */
    protected function displayModelExample(string $modelName, array $fields = []): void
    {
        $this->line('');
        $this->info('Exemplo de como atualizar seu model:');
        $this->line('');
        $this->line("use LucasBritoWdt\\LaravelDatabaseFts\\Traits\\Searchable;");
        $this->line('');
        $this->line("class {$modelName} extends Model");
        $this->line('{');
        $this->line('    use Searchable;');
        $this->line('');
        $this->line("    protected static array \$searchable = [");
        $this->line("        'campo1',");
        $this->line("        'campo2',");
        $this->line('    ];');
        $this->line('}');
        $this->line('');
    }

    /**
     * Obtém todas as traits usadas por uma classe (incluindo traits de traits).
     *
     * @param string $class
     * @param array $processed
     * @return array
     */
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
}
