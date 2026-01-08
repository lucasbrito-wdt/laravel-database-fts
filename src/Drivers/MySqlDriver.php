<?php

namespace LucasBritoWdt\LaravelDatabaseFts\Drivers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class MySqlDriver implements DriverInterface
{
    public function __construct(protected ?string $connectionName = null) {}

    public function getDriverName(): string
    {
        return 'mysql';
    }

    public function createIndex(string $tableName, array $columns, ?string $indexName = null): void
    {
        $connection = $this->getConnection();

        // Verifica se realmente é uma conexão MySQL
        if ($connection->getDriverName() !== 'mysql') {
            throw new \RuntimeException(
                "MySqlDriver não pode ser usado com conexão '{$connection->getDriverName()}'. " .
                    "Use PostgresDriver para PostgreSQL ou verifique a configuração do driver."
            );
        }

        $indexName = $indexName ?? "{$tableName}_search_ft_idx";

        // Verifica se o índice já existe (MySQL não suporta IF NOT EXISTS para FULLTEXT)
        if ($this->indexExists($tableName, $indexName)) {
            return;
        }

        // MySQL FULLTEXT index requer colunas separadas, não expressão
        $columnsList = implode(', ', $columns);

        $connection->statement("
            CREATE FULLTEXT INDEX {$indexName}
            ON {$tableName} ({$columnsList})
        ");
    }

    /**
     * Verifica se um índice FULLTEXT já existe.
     *
     * @param string $tableName
     * @param string $indexName
     * @return bool
     */
    protected function indexExists(string $tableName, string $indexName): bool
    {
        try {
            $connection = $this->getConnection();
            $result = $connection->selectOne("
                SELECT COUNT(*) as count
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                AND table_name = ?
                AND index_name = ?
            ", [$tableName, $indexName]);

            return ($result->count ?? 0) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function dropIndex(string $tableName, ?string $indexName = null): void
    {
        $indexName = $indexName ?? "{$tableName}_search_ft_idx";
        $this->getConnection()->statement("DROP INDEX {$indexName} ON {$tableName};");
    }

    public function applySearch(Builder $query, array $columns, string $term, ?float $similarity = null): Builder
    {
        $connection = $query->getConnection();

        // Verifica se realmente é uma conexão MySQL
        if ($connection->getDriverName() !== 'mysql') {
            throw new \RuntimeException(
                "MySqlDriver não pode ser usado com conexão '{$connection->getDriverName()}'. " .
                    "Use PostgresDriver para PostgreSQL ou verifique a configuração do driver."
            );
        }

        // MySQL FULLTEXT usa MATCH() AGAINST() para busca e ranking
        $columnsList = implode(', ', $columns);
        $searchMode = $this->getSearchMode($similarity);

        // Aplica busca usando MATCH() AGAINST()
        $query->whereRaw(
            "MATCH({$columnsList}) AGAINST(? {$searchMode})",
            [$term]
        );

        // Ordena por relevância (score do MATCH)
        return $query->orderByRaw(
            "MATCH({$columnsList}) AGAINST(? {$searchMode}) DESC",
            [$term]
        );
    }

    /**
     * Determina o modo de busca baseado no threshold.
     * Para MySQL, o parâmetro similarity é usado para determinar o modo.
     * 
     * @param float|null $similarity
     * @return string
     */
    protected function getSearchMode(?float $similarity): string
    {
        // Se similarity for muito baixo (< 0.1), usa BOOLEAN MODE para mais flexibilidade
        // Caso contrário, usa NATURAL LANGUAGE MODE (padrão)
        if ($similarity !== null && $similarity < 0.1) {
            return 'IN BOOLEAN MODE';
        }

        return 'IN NATURAL LANGUAGE MODE';
    }

    protected function getConnection()
    {
        return DB::connection($this->connectionName);
    }
}
