<?php

use Illuminate\Database\Migrations\Migration;
use LucasBritoWdt\LaravelDatabaseFts\Traits\SearchableMigration;

return new class extends Migration
{
    use SearchableMigration;

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        $tableName = '{{TABLE}}';

        // Obtém os campos pesquisáveis da model automaticamente
        $fields = $this->getSearchableFields($tableName);

        if (empty($fields)) {
            throw new \RuntimeException(
                "Não foi possível encontrar os campos pesquisáveis para a tabela '{$tableName}'. " .
                    "Certifique-se de que a model correspondente usa o trait Searchable e define o array \$searchable."
            );
        }

        // Usa o helper para criar o índice
        $this->createSearchableIndex($tableName, $fields);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        $tableName = '{{TABLE}}';

        // Usa o helper para remover o índice
        $this->dropSearchableIndex($tableName);
    }
};
