<?php

/**
 * Configuração do Laravel Database Full-Text Search
 *
 * Este arquivo contém todas as configurações para o pacote de busca
 * full-text que suporta PostgreSQL (pg_trgm) e MySQL (FULLTEXT).
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Driver de Busca
    |--------------------------------------------------------------------------
    |
    | Define qual driver de busca será usado. Opções:
    | - 'auto': Detecta automaticamente baseado na conexão do banco de dados
    | - 'postgres': Força uso do driver PostgreSQL (pg_trgm)
    | - 'mysql': Força uso do driver MySQL (FULLTEXT)
    |
    | Recomendado: 'auto' para detecção automática baseada na conexão ativa.
    |
    */
    'driver' => env('FTS_DRIVER', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Threshold de Similaridade
    |--------------------------------------------------------------------------
    |
    | Threshold padrão para busca por similaridade.
    | Valores entre 0.0 e 1.0. Quanto menor, mais resultados serão retornados.
    |
    | Para PostgreSQL (pg_trgm):
    |   - Controla a similaridade de trigramas (0.0 a 1.0)
    |   - Valores menores retornam mais resultados
    |
    | Para MySQL (FULLTEXT):
    |   - Se >= 0.3: usa NATURAL LANGUAGE MODE (busca mais precisa)
    |   - Se < 0.3: usa BOOLEAN MODE (busca mais flexível)
    |
    */
    'similarity_threshold' => env('FTS_SIMILARITY_THRESHOLD', 0.2),

    /*
    |--------------------------------------------------------------------------
    | Configurações de ACL (Access Control List)
    |--------------------------------------------------------------------------
    |
    | Configurações para controle de acesso baseado em visibilidade.
    |
    */
    'acl' => [
        'column' => env('FTS_ACL_COLUMN', 'visibility'),
        'ranking_multipliers' => [
            'public' => 1.2,
            'internal' => 1.0,
            'private' => 0.5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Métricas e Logging
    |--------------------------------------------------------------------------
    |
    | Quando habilitado, todas as buscas são logadas com informações de
    | performance (termo, tempo de execução, quantidade de resultados, driver usado).
    |
    | Útil para monitoramento e otimização de queries de busca.
    |
    */
    'metrics' => [
        'enabled' => env('FTS_METRICS_ENABLED', true),
        'log_channel' => env('FTS_LOG_CHANNEL', 'daily'),
    ],
];
