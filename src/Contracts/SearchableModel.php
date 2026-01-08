<?php

namespace LucasBritoWdt\LaravelDatabaseFts\Contracts;

use Illuminate\Database\Eloquent\Builder;

/**
 * Interface SearchableModel
 *
 * Contrato para models que implementam busca por similaridade usando pg_trgm.
 *
 * @package LucasBritoWdt\LaravelDatabaseFts\Contracts
 */
interface SearchableModel
{
    /**
     * Scope para busca por similaridade.
     *
     * @param Builder $query
     * @param string $term Termo de busca
     * @param float|null $similarity Threshold de similaridade (opcional)
     * @param array|null $acl Array de valores de visibilidade permitidos (opcional)
     * @return Builder
     */
    public static function scopeSearch(
        Builder $query,
        string $term,
        ?float $similarity = null,
        ?array $acl = null
    ): Builder;

    /**
     * Retorna as colunas pesquisáveis do model.
     *
     * @return array
     */
    public static function getSearchableColumns(): array;
}
