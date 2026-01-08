<?php

namespace LucasBritoWdt\LaravelDatabaseFts\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use LucasBritoWdt\LaravelDatabaseFts\Drivers\DriverFactory;

/**
 * Trait Searchable
 *
 * Fornece funcionalidades de busca usando drivers de banco de dados (PostgreSQL pg_trgm ou MySQL FULLTEXT).
 * Inclui suporte a busca parcial, ACL e threshold de similaridade configurável.
 *
 * @package LucasBritoWdt\LaravelDatabaseFts\Traits
 */
trait Searchable
{

    /**
     * Scope para busca usando o driver apropriado (PostgreSQL ou MySQL).
     *
     * @param Builder $query
     * @param string $term Termo de busca
     * @param float|null $similarity Threshold de similaridade (0.0 a 1.0)
     * @param array|null $acl Array de valores de visibilidade permitidos
     * @return Builder
     */
    public static function scopeSearch(
        Builder $query,
        string $term,
        ?float $similarity = null,
        ?array $acl = null
    ): Builder {
        $similarity = $similarity ?? Config::get('fts.similarity_threshold', 0.2);
        $acl = $acl ?? [];
        $columns = static::getSearchableColumns();

        // Obtém o driver apropriado baseado na conexão do model
        $connectionName = $query->getModel()->getConnectionName();
        $driver = DriverFactory::make($connectionName);

        // Aplica busca usando o driver
        $query = $driver->applySearch($query, $columns, $term, $similarity);

        // Aplica filtro ACL se fornecido
        if (!empty($acl)) {
            $aclColumn = Config::get('fts.acl.column', 'visibility');
            $query->whereIn($aclColumn, $acl);
        }

        return $query;
    }

    /**
     * Retorna as colunas pesquisáveis do model.
     *
     * @return array
     * @throws \RuntimeException Se a propriedade $searchable não estiver definida
     */
    public static function getSearchableColumns(): array
    {
        if (!property_exists(static::class, 'searchable')) {
            throw new \RuntimeException(
                'Model must define protected static $searchable array'
            );
        }
        return static::$searchable;
    }
}
