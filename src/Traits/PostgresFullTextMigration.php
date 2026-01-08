<?php

namespace LucasBritoWdt\LaravelDatabaseFts\Traits;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trait PostgresFullTextMigration
 *
 * Trait para usar em migrations que facilita a criação de índices GIN
 * com pg_trgm para busca por similaridade.
 *
 * @package LucasBritoWdt\LaravelDatabaseFts\Traits
 */
trait PostgresFullTextMigration
{
    /**
     * Adiciona índice GIN com gin_trgm_ops para busca por similaridade.
     * Cria automaticamente a extensão pg_trgm se não existir.
     *
     * @param Blueprint $table
     * @param array $fields Campos que serão indexados na expressão concat_ws
     * @param string|null $indexName Nome customizado do índice (opcional)
     * @return void
     */
    public function addSearchableIndex(
        Blueprint $table,
        array $fields,
        ?string $indexName = null
    ): void {
        $tableName = $table->getTable();
        $connection = Schema::getConnection();

        // Cria extensão pg_trgm automaticamente
        $connection->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm;');

        // Gera nome do índice
        $indexName = $indexName ?? "{$tableName}_search_trgm_idx";

        // Cria expressão imutável usando || (concatenação) e COALESCE
        // Isso é necessário porque concat_ws não é IMMUTABLE
        $expressionParts = [];
        foreach ($fields as $field) {
            $expressionParts[] = "COALESCE({$field}::text, '')";
        }
        $expression = '(' . implode(" || ' ' || ", $expressionParts) . ')';

        // Cria índice GIN
        $connection->statement("
            CREATE INDEX IF NOT EXISTS {$indexName}
            ON {$tableName}
            USING GIN ({$expression} gin_trgm_ops);
        ");
    }

    /**
     * Remove índice de busca por similaridade.
     *
     * @param Blueprint $table
     * @param string|null $indexName Nome do índice a ser removido (opcional)
     * @return void
     */
    public function dropSearchableIndex(
        Blueprint $table,
        ?string $indexName = null
    ): void {
        $tableName = $table->getTable();
        $indexName = $indexName ?? "{$tableName}_search_trgm_idx";
        $connection = Schema::getConnection();
        $connection->statement("DROP INDEX IF EXISTS {$indexName};");
    }
}
