<?php

namespace LucasBritoWdt\LaravelDatabaseFts\Drivers;

use Illuminate\Database\Eloquent\Builder;
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

        // Valida se há colunas
        if (empty($columns)) {
            throw new \InvalidArgumentException('Nenhuma coluna fornecida para criar índice FULLTEXT.');
        }

        $indexName = $indexName ?? "{$tableName}_search_ft_idx";

        // Verifica se o índice já existe (MySQL não suporta IF NOT EXISTS para FULLTEXT)
        if ($this->indexExists($tableName, $indexName)) {
            return;
        }

        // Verifica se a tabela existe
        $schemaBuilder = $connection->getSchemaBuilder();
        if (!$schemaBuilder->hasTable($tableName)) {
            throw new \RuntimeException("Tabela '{$tableName}' não existe. Crie a tabela antes de criar o índice FULLTEXT.");
        }

        // MySQL FULLTEXT index requer colunas separadas, não expressão
        // Escapa os nomes das colunas com backticks
        $columnsList = implode(', ', array_map(function ($column) {
            return "`{$column}`";
        }, $columns));

        try {
            $connection->statement("
                CREATE FULLTEXT INDEX `{$indexName}`
                ON `{$tableName}` ({$columnsList})
            ");
        } catch (\Exception $e) {
            // Verifica se é erro de tipo de coluna não suportado
            if (str_contains($e->getMessage(), 'FULLTEXT') || str_contains($e->getMessage(), '1214')) {
                throw new \RuntimeException(
                    "Erro ao criar índice FULLTEXT: As colunas devem ser do tipo CHAR, VARCHAR ou TEXT. " .
                        "Erro original: " . $e->getMessage()
                );
            }
            throw $e;
        }
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
        return $this->indexExistsForConnection($this->getConnection(), $tableName, $indexName);
    }

    /**
     * Verifica se um índice FULLTEXT existe usando uma conexão específica.
     *
     * @param \Illuminate\Database\Connection $connection
     * @param string $tableName
     * @param string $indexName
     * @return bool
     */
    protected function indexExistsForConnection($connection, string $tableName, string $indexName): bool
    {
        try {
            // Usa o nome do banco de dados da conexão
            $databaseName = $connection->getDatabaseName();

            $result = $connection->selectOne("
                SELECT COUNT(*) as count
                FROM information_schema.statistics
                WHERE table_schema = ?
                AND table_name = ?
                AND index_name = ?
            ", [$databaseName, $tableName, $indexName]);

            return ($result->count ?? 0) > 0;
        } catch (\Exception $e) {
            // Em caso de erro, assume que o índice não existe
            return false;
        }
    }

    public function dropIndex(string $tableName, ?string $indexName = null): void
    {
        $connection = $this->getConnection();
        $indexName = $indexName ?? "{$tableName}_search_ft_idx";

        // Verifica se o índice existe antes de tentar remover
        if (!$this->indexExists($tableName, $indexName)) {
            return; // Índice não existe, não precisa remover
        }

        $connection->statement("DROP INDEX `{$indexName}` ON `{$tableName}`;");
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

        // Limpa e sanitiza o termo de busca
        $term = trim($term);

        // Remove caracteres de controle e normaliza espaços
        $term = preg_replace('/[\x00-\x1F\x7F]/u', '', $term);
        $term = preg_replace('/\s+/', ' ', $term);

        // Valida se o termo não está vazio após sanitização
        if (empty($term)) {
            return $query->whereRaw('1 = 0'); // Retorna vazio se termo estiver vazio
        }

        // Para NATURAL LANGUAGE MODE, remove caracteres especiais que podem causar problemas
        // Para BOOLEAN MODE, alguns caracteres são permitidos, mas vamos ser conservadores
        $searchMode = $this->getSearchMode($similarity);
        if (str_contains($searchMode, 'NATURAL LANGUAGE')) {
            // Remove apenas caracteres que definitivamente causam problemas
            $term = str_replace(['@'], ' ', $term);
            $term = trim($term);

            // Valida novamente
            if (empty($term)) {
                return $query->whereRaw('1 = 0');
            }
        }

        // MySQL FULLTEXT requer pelo menos 3 caracteres (ou 4 para InnoDB)
        // Se o termo for muito curto, retorna vazio (não usa LIKE)
        if (mb_strlen($term) < 3) {
            return $query->whereRaw('1 = 0'); // Retorna vazio se termo for muito curto
        }

        // Obtém o nome da tabela do modelo
        $tableName = $query->getModel()->getTable();

        // Verifica se existe um índice FULLTEXT para essas colunas
        // Se não existir, lança exceção (não usa LIKE como fallback)
        $indexName = "{$tableName}_search_ft_idx";
        if (!$this->indexExistsForConnection($connection, $tableName, $indexName)) {
            throw new \RuntimeException(
                "Índice FULLTEXT '{$indexName}' não encontrado na tabela '{$tableName}'. " .
                    "Execute a migration para criar o índice: php artisan make:searchable " . class_basename($query->getModel())
            );
        }

        // MySQL FULLTEXT usa MATCH() AGAINST() para busca e ranking
        $columnsList = implode(', ', array_map(function ($column) {
            return "`{$column}`";
        }, $columns));

        $searchMode = $this->getSearchMode($similarity);

        // Obtém as colunas atuais do query builder
        $currentColumns = $query->getQuery()->columns;
        $model = $query->getModel();
        $primaryKey = $model->getKeyName();

        // Apenas adiciona os campos necessários se não houver selects customizados
        // Isso preserva COMPLETAMENTE a configuração do Eloquent
        if (empty($currentColumns)) {
            // Se não há selects, adiciona tabela.* (comportamento padrão do Eloquent)
            $query->addSelect("{$tableName}.*");
        }
        // Se já existem selects customizados, NÃO MODIFICAMOS NADA
        // O usuário tem controle total sobre o que quer selecionar

        // Sempre adiciona o score de relevância como coluna extra
        // O score pode ser acessado via $model->relevance_score após a busca
        // Usa addSelect para preservar selects existentes
        $escapedTerm = $connection->getPdo()->quote($term);
        $query->addSelect(
            \DB::raw("MATCH({$columnsList}) AGAINST({$escapedTerm} {$searchMode}) as relevance_score")
        );

        // Aplica busca usando MATCH() AGAINST()
        // Usa > 0 para garantir que apenas resultados com relevância sejam retornados
        $query->whereRaw(
            "MATCH({$columnsList}) AGAINST(? {$searchMode}) > 0",
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
        // NATURAL LANGUAGE MODE é o padrão e mais eficiente
        // BOOLEAN MODE é usado apenas quando similarity é muito baixo (< 0.1)
        // indicando necessidade de busca mais flexível
        if ($similarity !== null && $similarity < 0.1) {
            return 'IN BOOLEAN MODE';
        }

        // NATURAL LANGUAGE MODE é o padrão recomendado
        // Retorna resultados ordenados por relevância automaticamente
        return 'IN NATURAL LANGUAGE MODE';
    }

    protected function getConnection()
    {
        return DB::connection($this->connectionName);
    }
}
