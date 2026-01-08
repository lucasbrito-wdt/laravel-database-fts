<?php

namespace LucasBritoWdt\LaravelDatabaseFts\Drivers;

use Illuminate\Database\Eloquent\Builder;

/**
 * Interface para drivers de busca.
 */
interface DriverInterface
{
    /**
     * Retorna o nome do driver (ex: postgres, mysql).
     */
    public function getDriverName(): string;

    /**
     * Cria índice de busca para a tabela e colunas fornecidas.
     *
     * @param string $tableName Nome da tabela.
     * @param array $columns Colunas que compõem o índice.
     * @param string|null $indexName Nome opcional do índice.
     */
    public function createIndex(string $tableName, array $columns, ?string $indexName = null): void;

    /**
     * Remove o índice de busca da tabela fornecida.
     *
     * @param string $tableName Nome da tabela.
     * @param string|null $indexName Nome opcional do índice.
     */
    public function dropIndex(string $tableName, ?string $indexName = null): void;

    /**
     * Aplica filtros e ordenação de busca ao Builder.
     *
     * @param Builder $query Query builder do model.
     * @param array $columns Colunas pesquisáveis.
     * @param string $term Termo de busca.
     * @param float|null $similarity Threshold opcional para ranking/filtragem.
     * @return Builder
     */
    public function applySearch(Builder $query, array $columns, string $term, ?float $similarity = null): Builder;
}
