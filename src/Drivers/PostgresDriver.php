<?php

namespace LucasBritoWdt\LaravelDatabaseFts\Drivers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class PostgresDriver implements DriverInterface
{
    public function __construct(protected ?string $connectionName = null) {}

    public function getDriverName(): string
    {
        return 'postgres';
    }

    public function createIndex(string $tableName, array $columns, ?string $indexName = null): void
    {
        $connection = $this->getConnection();

        // Verifica se realmente é uma conexão PostgreSQL
        if ($connection->getDriverName() !== 'pgsql') {
            throw new \RuntimeException(
                "PostgresDriver não pode ser usado com conexão '{$connection->getDriverName()}'. " .
                    "Use MySqlDriver para MySQL ou verifique a configuração do driver."
            );
        }

        // Garante extensão pg_trgm
        $connection->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm;');

        $indexName = $indexName ?? "{$tableName}_search_trgm_idx";
        $expression = $this->buildExpression($columns);

        $connection->statement("
            CREATE INDEX IF NOT EXISTS {$indexName}
            ON {$tableName}
            USING GIN ({$expression} gin_trgm_ops);
        ");
    }

    public function dropIndex(string $tableName, ?string $indexName = null): void
    {
        $indexName = $indexName ?? "{$tableName}_search_trgm_idx";
        $this->getConnection()->statement("DROP INDEX IF EXISTS {$indexName};");
    }

    public function applySearch(Builder $query, array $columns, string $term, ?float $similarity = null): Builder
    {
        $connection = $query->getConnection();

        // Verifica se realmente é uma conexão PostgreSQL
        if ($connection->getDriverName() !== 'pgsql') {
            throw new \RuntimeException(
                "PostgresDriver não pode ser usado com conexão '{$connection->getDriverName()}'. " .
                    "Use MySqlDriver para MySQL ou verifique a configuração do driver."
            );
        }

        $similarity = $similarity ?? Config::get('fts.similarity_threshold', 0.2);
        $expression = $this->buildExpression($columns);

        $query->whereRaw(
            "{$expression} ILIKE ? OR similarity({$expression}, ?) > ?",
            [$term . '%', $term, $similarity]
        );

        return $query->orderByRaw(
            "similarity({$expression}, ?) DESC",
            [$term]
        );
    }

    protected function buildExpression(array $columns): string
    {
        $parts = [];

        foreach ($columns as $column) {
            $parts[] = "COALESCE({$column}::text, '')";
        }

        return '(' . implode(" || ' ' || ", $parts) . ')';
    }

    protected function getConnection()
    {
        return DB::connection($this->connectionName);
    }
}
