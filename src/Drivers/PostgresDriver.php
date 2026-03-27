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

        $expression = $this->buildExpression($columns);
        $currentColumns = $query->getQuery()->columns;

        // Quando search() é chamado dentro de whereHas(), o Laravel já definiu columns = [Expression('*')]
        // para montar a subquery EXISTS. Nesse contexto, adicionar SELECT e ORDER BY é incorreto:
        // o EXISTS só avalia o WHERE. Aplicamos apenas o filtro.
        if ($this->isExistenceSubquery($currentColumns)) {
            return $this->applyWhereOnly($query, $expression, $term, $similarity, $connection);
        }

        // Contexto normal: adiciona SELECT, WHERE e ORDER BY
        $tableName = $query->getModel()->getTable();

        if (empty($currentColumns)) {
            $query->addSelect("{$tableName}.*");
        }

        if ($this->hasTrgmExtension($connection)) {
            $similarity = $similarity ?? Config::get('fts.similarity_threshold', 0.2);

            $query->addSelect(\DB::raw("similarity({$expression}, ?) as relevance_score"));
            $query->addBinding($term, 'select');

            $query->whereRaw(
                "{$expression} ILIKE ? OR similarity({$expression}, ?) > ?",
                [$term . '%', $term, $similarity]
            );

            return $query->orderByRaw("similarity({$expression}, ?) DESC", [$term]);
        }

        // Fallback: LIKE quando pg_trgm não está disponível
        $query->addSelect(\DB::raw("1 as relevance_score"));
        $query->whereRaw("{$expression} ILIKE ?", ['%' . $term . '%']);

        return $query;
    }

    /**
     * Aplica apenas o WHERE sem SELECT nem ORDER BY.
     * Usado quando search() é chamado dentro de whereHas() / whereExists().
     */
    protected function applyWhereOnly(Builder $query, string $expression, string $term, ?float $similarity, $connection): Builder
    {
        if ($this->hasTrgmExtension($connection)) {
            $similarity = $similarity ?? Config::get('fts.similarity_threshold', 0.2);

            return $query->whereRaw(
                "{$expression} ILIKE ? OR similarity({$expression}, ?) > ?",
                [$term . '%', $term, $similarity]
            );
        }

        return $query->whereRaw("{$expression} ILIKE ?", ['%' . $term . '%']);
    }

    /**
     * Detecta se a query está sendo usada como subquery EXISTS (ex: dentro de whereHas).
     * O Laravel pode definir columns = [Expression('*')] ou ['*'] dependendo da versão.
     * BelongsTo::getRelationExistenceQuery usa $columns = ['*'] por padrão (string simples).
     */
    protected function isExistenceSubquery(?array $currentColumns): bool
    {
        if (empty($currentColumns) || count($currentColumns) !== 1) {
            return false;
        }

        $col = $currentColumns[0];

        return $col instanceof \Illuminate\Database\Query\Expression || $col === '*';
    }

    protected function hasTrgmExtension($connection): bool
    {
        try {
            $result = $connection->selectOne(
                "SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm'"
            );

            return $result !== null;
        } catch (\Exception) {
            return false;
        }
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
