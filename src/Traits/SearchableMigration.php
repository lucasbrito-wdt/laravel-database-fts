<?php

namespace LucasBritoWdt\LaravelDatabaseFts\Traits;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LucasBritoWdt\LaravelDatabaseFts\Drivers\DriverFactory;

/**
 * Trait SearchableMigration
 *
 * Trait com helpers para migrations de busca usando drivers de banco de dados.
 * Fornece métodos para criar índices, encontrar models e obter campos pesquisáveis.
 *
 * @package LucasBritoWdt\LaravelDatabaseFts\Traits
 */
trait SearchableMigration
{
    /**
     * Helper para criar índice de busca usando o driver apropriado.
     * Pode ser chamado manualmente se necessário.
     *
     * @param string $tableName Nome da tabela
     * @param array $fields Campos que serão indexados
     * @param string|null $indexName Nome customizado do índice (opcional)
     * @return void
     */
    protected function createSearchableIndex(
        string $tableName,
        array $fields,
        ?string $indexName = null
    ): void {
        $connection = Schema::getConnection();
        $connectionName = $connection->getName();

        // Obtém o driver apropriado
        $driver = DriverFactory::make($connectionName);

        // Usa o driver para criar o índice
        $driver->createIndex($tableName, $fields, $indexName);
    }

    /**
     * Helper para remover índice de busca.
     * Pode ser chamado manualmente se necessário.
     *
     * @param string $tableName Nome da tabela
     * @param string|null $indexName Nome do índice a ser removido (opcional)
     * @return void
     */
    protected function dropSearchableIndex(
        string $tableName,
        ?string $indexName = null
    ): void {
        $connection = Schema::getConnection();
        $connectionName = $connection->getName();

        // Obtém o driver apropriado
        $driver = DriverFactory::make($connectionName);

        // Usa o driver para remover o índice
        $driver->dropIndex($tableName, $indexName);
    }

    /**
     * Obtém os campos pesquisáveis da model automaticamente.
     * Busca a model que corresponde à tabela e lê o array $searchable.
     *
     * @param string $tableName Nome da tabela
     * @return array
     */
    protected function getSearchableFields(string $tableName): array
    {
        $modelClass = $this->findModelForTable($tableName);

        if (!$modelClass) {
            return [];
        }

        try {
            // Verifica se a model tem a propriedade $searchable
            if (property_exists($modelClass, 'searchable')) {
                $reflection = new \ReflectionClass($modelClass);
                $property = $reflection->getStaticPropertyValue('searchable');

                if (is_array($property) && !empty($property)) {
                    return $property;
                }
            }
        } catch (\Exception $e) {
            // Se houver erro, retorna vazio
        }

        return [];
    }

    /**
     * Encontra a classe do model que corresponde à tabela.
     * Busca em namespaces comuns e estruturas customizadas (ex: Domains/{domain}/Models/{model}).
     *
     * @param string $tableName
     * @return string|null
     */
    protected function findModelForTable(string $tableName): ?string
    {
        // Namespaces padrão
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

        // Tenta encontrar models que usam a trait Searchable
        foreach ($namespaces as $namespace) {
            $modelClass = $this->findModelInNamespace($namespace, $tableName);
            if ($modelClass) {
                return $modelClass;
            }
        }

        // Fallback: tenta encontrar pelo nome da tabela (plural -> singular)
        $singularName = Str::singular($tableName);
        $modelName = Str::studly($singularName);

        foreach ($namespaces as $namespace) {
            $className = $namespace . $modelName;
            if (class_exists($className)) {
                try {
                    $model = new $className();
                    if ($model->getTable() === $tableName) {
                        $traits = $this->getClassTraits($className);
                        $searchableTrait = 'LucasBritoWdt\\LaravelDatabaseFts\\Traits\\Searchable';

                        if (in_array($searchableTrait, $traits)) {
                            return $className;
                        }
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Busca model em um namespace específico.
     *
     * @param string $namespace
     * @param string $tableName
     * @return string|null
     */
    protected function findModelInNamespace(string $namespace, string $tableName): ?string
    {
        // Converte namespace para caminho de arquivo
        $relativePath = str_replace('App\\', 'app/', $namespace);
        $modelsPath = base_path(str_replace('\\', '/', $relativePath));

        if (!is_dir($modelsPath)) {
            return null;
        }

        $files = glob($modelsPath . '*.php');

        foreach ($files as $file) {
            $className = $namespace . basename($file, '.php');

            if (!class_exists($className)) {
                continue;
            }

            // Verifica se a classe usa a trait Searchable
            $traits = $this->getClassTraits($className);
            $searchableTrait = 'LucasBritoWdt\\LaravelDatabaseFts\\Traits\\Searchable';

            if (!in_array($searchableTrait, $traits)) {
                continue;
            }

            // Verifica se a tabela do model corresponde
            try {
                $model = new $className();
                if ($model->getTable() === $tableName) {
                    return $className;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
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
}
