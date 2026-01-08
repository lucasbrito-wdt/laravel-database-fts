# Laravel Database Full-Text Search

Busca Full-Text nativa para Laravel com suporte a **PostgreSQL** (pg_trgm) e **MySQL** (FULLTEXT), incluindo multi-tenant, ACL e busca parcial automática.

## Características

- ✅ **Multi-Driver**: Suporta PostgreSQL (pg_trgm) e MySQL (FULLTEXT)
- ✅ **Detecção Automática**: Detecta automaticamente o banco de dados e usa o driver apropriado
- ✅ **Motor nativo**: Zero dependências externas, sem serviços adicionais
- ✅ **Índices otimizados**: GIN com gin_trgm_ops (PostgreSQL) ou FULLTEXT (MySQL)
- ✅ **Busca parcial automática**: Funciona com termos incompletos (ex: "adm" encontra "admin") - PostgreSQL
- ✅ **Sem colunas extras**: Não cria colunas adicionais, apenas índices
- ✅ **Detecção automática**: Lê campos da model automaticamente via trait
- ✅ **Método customizado no Blueprint**: Use `$table->searchableIndex()` diretamente em `Schema::table()`
- ✅ **Helpers na migration gerada**: Métodos `createSearchableIndex()` e `dropSearchableIndex()` disponíveis nas migrations geradas pelo comando
- ✅ **Multi-tenant**: Isolamento automático por tenant_id
- ✅ **ACL**: Controle de acesso baseado em visibilidade
- ✅ **Similaridade configurável**: Threshold ajustável para precisão vs recall
- ✅ **Suporte a estruturas customizadas**: Funciona com `Domains/*/Models/*` e outros namespaces

## Requisitos

- PHP >= 8.1
- Laravel >= 10.0
- **PostgreSQL >= 12.0** (com extensão `pg_trgm` - criada automaticamente) **OU**
- **MySQL >= 5.7** (com engine InnoDB ou MyISAM)

## Instalação

```bash
composer require lucasbrito-wdt/laravel-database-fts
```

Publique o arquivo de configuração:

```bash
php artisan vendor:publish --tag=fts-config
```

## Configuração

O arquivo de configuração `config/fts.php` contém todas as opções:

```php
return [
    'similarity_threshold' => 0.2,
    
    'tenancy' => [
        'enabled' => true,
        'column' => 'tenant_id',
    ],
    
    'acl' => [
        'column' => 'visibility',
        'ranking_multipliers' => [
            'public' => 1.2,
            'internal' => 1.0,
            'private' => 0.5,
        ],
    ],
    
    'metrics' => [
        'enabled' => true,
        'log_channel' => 'daily',
    ],
];
```

## Uso Rápido

### Escolha o Método de Criação do Índice

| Método | Quando Usar | Exemplo |
|--------|-------------|---------|
| **`$table->searchableIndex()`** | Ao criar índice em tabela existente | `Schema::table()` |
| **`make:searchable`** | Para tabelas existentes ou múltiplas models | `php artisan make:searchable Post` |
| **Trait PostgresFullTextMigration** | Controle manual avançado | `$this->addSearchableIndex($table, ...)` |
| **Helpers (migration gerada)** | Só em migrations geradas pelo comando | `$this->createSearchableIndex()` |

### 1. Configurar o Model

Primeiro, adicione o trait `Searchable` e defina os campos pesquisáveis:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LucasBritoWdt\LaravelDatabaseFts\Traits\Searchable;

class Post extends Model
{
    use Searchable;

    protected static array $searchable = [
        'title',
        'body',
    ];
}
```

**Para estruturas customizadas (ex: Domains):**

```php
namespace App\Domains\Blog\Models;

use Illuminate\Database\Eloquent\Model;
use LucasBritoWdt\LaravelDatabaseFts\Traits\Searchable;

class Post extends Model
{
    use Searchable;

    protected static array $searchable = [
        'title',
        'body',
    ];
}
```

### 2. Gerar Migration Automaticamente

#### Opção 1: Para uma model específica

Use o comando Artisan para gerar a migration para uma model específica. **Não precisa passar os campos** - eles são lidos automaticamente da model:

```bash
php artisan make:searchable Post
```

#### Opção 2: Para todas as models automaticamente

Gere migrations para **todas as models** que usam o trait `Searchable` de uma vez:

```bash
php artisan make:searchable --all
```

Ou simplesmente:

```bash
php artisan make:searchable
```

Este comando:

- 🔍 Busca automaticamente todas as models em `App\Models\`, `App\` e `App\Domains\*\Models\`
- ✅ Verifica quais usam o trait `Searchable`
- ✅ Verifica se têm o array `$searchable` definido
- 📝 Gera migrations para todas as models encontradas
- ⚠️  Ignora models que já têm migration existente
- 📊 Mostra resumo com sucessos e erros

**Exemplo de saída:**

```
Buscando todas as models que usam o trait Searchable...

Encontradas 3 model(s):
  - App\Models\Post
  - App\Domains\Auth\Models\User
  - App\Domains\Blog\Models\Article

Deseja gerar migrations para todas essas models? (yes/no) [yes]:
  ✅ Post: Migration criada
  ✅ User: Migration criada
  ⚠️  Article: Migration já existe (2026_01_08_030322_add_searchable_index_to_articles_table.php)

Concluído! 2 migration(s) criada(s), 0 erro(s).
```

**Detalhes do comando:**

- Detecta automaticamente a model (busca em `App\Models\`, `App\` e `App\Domains\{domain}\Models\{model}`)
- Verifica se a model usa o trait `Searchable`
- Lê os campos do array `$searchable` automaticamente
- Cria migration que lê os campos da model na execução
- Cria índice GIN usando `gin_trgm_ops`
- Cria extensão `pg_trgm` automaticamente

**A migration gerada lê automaticamente os campos da model quando executada!**

### 3. Criar Índice no Schema::create()

**⚠️ IMPORTANTE:** Para usar `searchableIndex()` dentro de `Schema::create()`, você precisa criar o índice **APÓS** a tabela ser criada:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Primeiro, cria a tabela
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->timestamps();
        });

        // Depois, cria o índice (após a tabela existir)
        Schema::table('posts', function (Blueprint $table) {
            $table->searchableIndex(['title', 'body']);
            
            // Ou com nome customizado
            // $table->searchableIndex(['title', 'body'], 'posts_custom_search_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
```

**Alternativa:** Use o trait `PostgresFullTextMigration`:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LucasBritoWdt\LaravelDatabaseFts\Traits\PostgresFullTextMigration;

return new class extends Migration
{
    use PostgresFullTextMigration;

    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->timestamps();
        });

        // Cria o índice após a tabela ser criada
        Schema::table('posts', function (Blueprint $table) {
            $this->addSearchableIndex($table, ['title', 'body']);
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $this->dropSearchableIndex($table);
        });

        Schema::dropIfExists('posts');
    }
};
```

**Nota:** O método `createSearchableIndex()` só está disponível nas migrations geradas pelo comando `make:searchable`. Para migrations manuais, use `Schema::table()` com `searchableIndex()` ou o trait `PostgresFullTextMigration`.

**Para adicionar a uma tabela existente:**

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->searchableIndex(['title', 'body']);
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // O método dropSearchableIndex não está disponível no Blueprint
            // Use o helper da migration ou SQL direto
        });
    }
};
```

### 4. Usar Helpers na Migration

A migration gerada pelo comando `make:searchable` inclui métodos helper que podem ser usados manualmente:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'posts';
        $fields = ['title', 'body'];

        // Usa o helper para criar o índice
        $this->createSearchableIndex($tableName, $fields);
        
        // Ou com nome customizado
        // $this->createSearchableIndex($tableName, $fields, 'posts_custom_idx');
    }

    public function down(): void
    {
        $tableName = 'posts';
        
        // Usa o helper para remover o índice
        $this->dropSearchableIndex($tableName);
        
        // Ou com nome customizado
        // $this->dropSearchableIndex($tableName, 'posts_custom_idx');
    }

    /**
     * Helper para criar índice GIN com pg_trgm para busca por similaridade.
     * Pode ser chamado manualmente se necessário.
     */
    protected function createSearchableIndex(
        string $tableName,
        array $fields,
        ?string $indexName = null
    ): void {
        // Implementação automática na migration gerada
    }

    /**
     * Helper para remover índice de busca por similaridade.
     * Pode ser chamado manualmente se necessário.
     */
    protected function dropSearchableIndex(
        string $tableName,
        ?string $indexName = null
    ): void {
        // Implementação automática na migration gerada
    }
}
```

### 5. Usar Trait PostgresFullTextMigration (Método Legado)

Alternativamente, você pode usar a trait `PostgresFullTextMigration` diretamente na sua migration:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LucasBritoWdt\LaravelDatabaseFts\Traits\PostgresFullTextMigration;

class CreatePostsTable extends Migration
{
    use PostgresFullTextMigration;

    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('body');
            $table->timestamps();

            // Adiciona índice para busca por similaridade
            $this->addSearchableIndex($table, ['title', 'body']);
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $this->dropSearchableIndex($table);
        });

        Schema::dropIfExists('posts');
    }
}
```

### 6. Métodos Disponíveis para Criar Índices

O pacote oferece três formas de criar índices de busca:

#### Opção 1: Método Customizado no Blueprint (Mais Simples) ⭐

Use diretamente em `Schema::create()` ou `Schema::table()`:

```php
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->text('body');
    
    // Cria índice automaticamente
    $table->searchableIndex(['title', 'body']);
    
    // Com nome customizado
    // $table->searchableIndex(['title', 'body'], 'posts_custom_idx');
});
```

**Vantagens:**

- ✅ Mais simples e direto
- ✅ Funciona com method chaining
- ✅ Não precisa de traits ou imports extras
- ✅ Cria extensão `pg_trgm` automaticamente

#### Opção 2: Helpers na Migration (Gerada pelo Comando)

A migration gerada pelo `make:searchable` inclui métodos helper:

```php
public function up(): void
{
    // Helper para criar índice
    $this->createSearchableIndex('posts', ['title', 'body']);
    
    // Com nome customizado
    // $this->createSearchableIndex('posts', ['title', 'body'], 'custom_idx');
}

public function down(): void
{
    // Helper para remover índice
    $this->dropSearchableIndex('posts');
    
    // Com nome customizado
    // $this->dropSearchableIndex('posts', 'custom_idx');
}
```

**Vantagens:**

- ✅ Disponível automaticamente na migration gerada pelo comando `make:searchable`
- ✅ Permite controle total sobre a criação do índice
- ✅ Os métodos `createSearchableIndex()` e `dropSearchableIndex()` são incluídos automaticamente

**Nota:** Esses métodos helper só estão disponíveis nas migrations geradas pelo comando `make:searchable`. Para migrations manuais, use `Schema::table()` com `searchableIndex()` ou o trait `PostgresFullTextMigration`.

#### Opção 3: Trait PostgresFullTextMigration (Método Legado)

Para compatibilidade com código existente:

```php
use LucasBritoWdt\LaravelDatabaseFts\Traits\PostgresFullTextMigration;

class CreatePostsTable extends Migration
{
    use PostgresFullTextMigration;

    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $this->addSearchableIndex($table, ['title', 'body']);
        });
    }
}
```

### 7. Buscar

**Busca simples:**

```php
$results = Post::search('gestão corporativa')->paginate(10);
```

**Busca com threshold customizado:**

```php
// Threshold menor = mais resultados (menos preciso)
$results = Post::search('gest', 0.1)->get();

// Threshold maior = menos resultados (mais preciso)
$results = Post::search('gestão', 0.5)->get();
```

**Busca com filtro ACL:**

```php
$results = Post::search('termo', null, ['public', 'internal'])->get();
```

**Busca parcial automática:**

```php
// Funciona mesmo com termo incompleto
$results = Post::search('adm')->get(); // Encontra "admin", "administrador", etc.
```

## Como Funciona a Migration Automática

A migration consolidada (`2024_01_01_000000_add_searchable_indexes_to_all_tables.php`) é carregada automaticamente pelo `FtsServiceProvider` e:

1. **Detecta automaticamente** todas as models que:
   - Estendem `Illuminate\Database\Eloquent\Model`
   - Usam o trait `LucasBritoWdt\LaravelDatabaseFts\Traits\Searchable`
   - Têm o array `$searchable` definido e não vazio

2. **Busca em múltiplos namespaces:**
   - `App\Models\*`
   - `App\*`
   - `App\Domains\*\Models\*` (estrutura de domínios)

3. **Cria índices automaticamente:**
   - Verifica se o índice já existe (idempotente)
   - Cria apenas índices que não existem
   - Usa a mesma expressão imutável do índice para garantir compatibilidade

4. **É segura para executar múltiplas vezes:**
   - Usa `CREATE INDEX IF NOT EXISTS`
   - Verifica existência antes de criar
   - Não duplica índices

**Vantagens:**

- ✅ Zero configuração - funciona automaticamente
- ✅ Detecta novas models automaticamente
- ✅ Idempotente - pode executar múltiplas vezes sem problemas
- ✅ Suporta estruturas customizadas (Domains, etc.)

## Funcionalidades Avançadas

### Detecção Automática de Campos

A migration gerada pelo comando `make:searchable` **lê automaticamente** os campos do array `$searchable` da model quando executada. Isso significa:

- ✅ Não precisa passar campos no comando
- ✅ Não precisa editar a migration manualmente
- ✅ Se você mudar os campos na model, basta recriar a migration
- ✅ Funciona com qualquer estrutura (App\Models, Domains, etc.)

**Como funciona:**

1. O comando `make:searchable Post` gera uma migration
2. A migration busca automaticamente a model que corresponde à tabela
3. Lê o array `$searchable` via Reflection
4. Cria o índice com os campos encontrados

### Suporte a Estruturas Customizadas

O pacote detecta automaticamente models em:

- `App\Models\*`
- `App\*`
- `App\Domains\*\Models\*` (estrutura de domínios)

**Exemplo com Domains:**

```php
// app/Domains/Auth/Models/User.php
namespace App\Domains\Auth\Models;

use LucasBritoWdt\LaravelDatabaseFts\Traits\Searchable;

class User extends Model
{
    use Searchable;
    
    protected static array $searchable = ['name', 'email'];
}
```

```bash
# O comando encontra automaticamente
php artisan make:searchable User
```

### Multi-tenant

O pacote inclui isolamento automático por tenant através do trait `HasTenantScope`. Todas as queries são automaticamente filtradas por `tenant_id` quando a tenancy está habilitada.

```php
// Automaticamente filtra por tenant_id do usuário atual
Post::search('termo')->get();
```

### ACL (Access Control List)

Filtre resultados por visibilidade:

```php
// Busca apenas em itens públicos e internos
$results = Post::search('termo', null, ['public', 'internal'])->get();
```

### Como Funciona

O pacote detecta automaticamente o banco de dados e usa o driver apropriado:

#### PostgreSQL (pg_trgm)

1. Divide strings em trigramas (grupos de 3 caracteres)
2. Calcula similaridade entre strings usando a função `similarity()`
3. Usa índice GIN com `gin_trgm_ops` para busca rápida
4. Combina múltiplos campos usando concatenação para busca unificada

#### MySQL (FULLTEXT)

1. Usa índices FULLTEXT nativos do MySQL
2. Busca usando `MATCH() AGAINST()` para relevância
3. Suporta modos NATURAL LANGUAGE e BOOLEAN
4. Ranking automático por relevância

**Recursos disponíveis:**

- **PostgreSQL**: Termos parciais (ex: "adm" encontra "admin"), erros leves de digitação
- **MySQL**: Busca por palavras completas com ranking de relevância
- Ambos: Múltiplos campos simultaneamente

### Threshold de Similaridade

O threshold controla quantos resultados serão retornados:

- **0.0 - 0.2**: Muitos resultados, menos preciso (padrão: 0.2)
- **0.3 - 0.5**: Balanceado
- **0.6 - 1.0**: Poucos resultados, muito preciso

Ajuste conforme necessário:

```php
// Mais resultados
Post::search('termo', 0.1)->get();

// Menos resultados, mais precisos
Post::search('termo', 0.4)->get();
```

## SearchService Multi-Model

Busque em múltiplos models simultaneamente com ranking unificado:

```php
use LucasBritoWdt\LaravelDatabaseFts\Services\SearchService;

$results = app(SearchService::class)
    ->register(Post::class)
    ->register(Document::class)
    ->register(Ticket::class)
    ->search('fluxo de caixa', null, ['public', 'internal']);
```

Os resultados são ordenados por score (similaridade) independente do model de origem.

## Estrutura do Banco de Dados

O pacote cria automaticamente os índices apropriados para cada banco:

**PostgreSQL:**

- Extensão `pg_trgm` (se não existir)
- Índice GIN com `gin_trgm_ops` na expressão concatenada

**MySQL:**

- Índice FULLTEXT nas colunas especificadas

**Não cria colunas extras** - apenas índices para performance.

## Exemplo de Índice Criado

O pacote cria índices apropriados para cada banco de dados:

### PostgreSQL

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE INDEX IF NOT EXISTS posts_search_trgm_idx
ON posts
USING GIN (
    (COALESCE(title::text, '') || ' ' || COALESCE(body::text, '')) gin_trgm_ops
);
```

**Por que não usar `concat_ws`?**

- A função `concat_ws` não é `IMMUTABLE` no PostgreSQL
- Índices requerem funções imutáveis
- A solução usa `COALESCE` e concatenação `||` que são imutáveis

### MySQL

```sql
CREATE FULLTEXT INDEX posts_search_ft_idx
ON posts (title, body);
```

## Fluxo Completo de Uso

### Opção A: Usando o Comando Artisan (Recomendado)

1. **Configure o Model:**

```php
class Post extends Model
{
    use Searchable;
    
    protected static array $searchable = ['title', 'body'];
}
```

1. **Gere a Migration:**

```bash
php artisan make:searchable Post
```

1. **Execute a Migration:**

```bash
php artisan migrate
```

A migration automaticamente:

- Encontra a model `Post`
- Lê os campos `['title', 'body']` do array `$searchable`
- Cria o índice com esses campos

1. **Use a Busca:**

```php
Post::search('termo')->get();
```

### Opção B: Criando Manualmente no Schema::create()

1. **Configure o Model:**

```php
class Post extends Model
{
    use Searchable;
    
    protected static array $searchable = ['title', 'body'];
}
```

1. **Crie a Migration Manualmente:**

```php
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->text('body');
    $table->timestamps();
    
    // Adiciona índice de busca
    $table->searchableIndex(['title', 'body']);
});
```

1. **Execute a Migration:**

```bash
php artisan migrate
```

1. **Use a Busca:**

```php
Post::search('termo')->get();
```

## Diferenças entre Drivers

### PostgreSQL (pg_trgm)

- ✅ Suporta busca parcial (ex: "adm" encontra "admin")
- ✅ Suporta busca por similaridade (tolerante a erros de digitação)
- ✅ Funciona bem com termos curtos
- ⚠️ Requer extensão `pg_trgm`

### MySQL (FULLTEXT)

- ✅ Busca nativa FULLTEXT do MySQL
- ✅ Ranking automático por relevância
- ✅ Suporta modos NATURAL LANGUAGE e BOOLEAN
- ⚠️ Requer palavras completas (não suporta busca parcial como pg_trgm)
- ⚠️ Funciona melhor com palavras de 3+ caracteres
- ⚠️ Tem lista de stopwords que pode afetar resultados

## Quando NÃO usar

Esta solução não é adequada para:

- ❌ Busca semântica / IA
- ❌ Busca em logs massivos
- ❌ Multitenancy extremo com shards

Nesses casos, Elasticsearch / Meilisearch são mais adequados.

## Comparação com Outras Soluções

| Solução | Quando Usar |
|---------|-------------|
| **Este pacote (PostgreSQL pg_trgm / MySQL FULLTEXT)** | Busca parcial (PostgreSQL), autocomplete, simplicidade, zero infra extra |
| **PostgreSQL FTS (tsvector)** | Busca por palavras completas, stemming, múltiplos idiomas |
| **Meilisearch / Elasticsearch** | Busca semântica, autocomplete avançado, escalabilidade extrema |

## Troubleshooting

### Erro: "function similarity does not exist"

A extensão `pg_trgm` não está habilitada. O pacote tenta criá-la automaticamente, mas se falhar, execute manualmente:

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
```

### Erro: "Não foi possível encontrar os campos pesquisáveis"

A migration não conseguiu encontrar a model ou ler os campos. Verifique:

1. A model usa o trait `Searchable`?
2. A model define o array `$searchable`?
3. O namespace da model está em `App\Models\`, `App\` ou `App\Domains\*\Models\`?

**Solução:** Você pode passar os campos manualmente na migration usando uma das opções:

**Opção 1: Método customizado no Blueprint (Recomendado):**

```php
Schema::table('posts', function (Blueprint $table) {
    $table->searchableIndex(['title', 'body']);
});
```

**Opção 2: Trait PostgresFullTextMigration:**

```php
use LucasBritoWdt\LaravelDatabaseFts\Traits\PostgresFullTextMigration;

class AddSearchableIndexToPostsTable extends Migration
{
    use PostgresFullTextMigration;

    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $this->addSearchableIndex($table, ['title', 'body']);
        });
    }
}
```

**Opção 3: Helper na migration gerada pelo comando:**

```php
// Só disponível em migrations geradas por: php artisan make:searchable Post
$this->createSearchableIndex('posts', ['title', 'body']);
```

### Performance lenta

Certifique-se de que o índice foi criado:

```sql
\d+ posts  -- Lista índices da tabela
```

Verifique se o índice `*_search_trgm_idx` existe.

### Tenant não está sendo filtrado

Verifique se:

1. `config('fts.tenancy.enabled')` está `true`
2. O tenant atual está disponível via `app('currentTenant')` ou `auth()->user()->tenant_id`

### Muitos/poucos resultados

Ajuste o threshold de similaridade:

```php
// Mais resultados
Post::search('termo', 0.1)->get();

// Menos resultados
Post::search('termo', 0.4)->get();
```

### Model não encontrada em estrutura customizada

Se sua model está em um namespace customizado que não é detectado automaticamente, você pode:

1. Passar o namespace completo no comando (se o Laravel suportar)
2. Ou criar a migration manualmente passando os campos

## Contribuindo

Contribuições são bem-vindas! Por favor, abra uma issue ou pull request.

## Licença

MIT License. Veja o arquivo [LICENSE](LICENSE) para mais detalhes.

## Referências

Este pacote é baseado nas melhores práticas documentadas em:

- [PostgreSQL pg_trgm Documentation](https://www.postgresql.org/docs/current/pgtrgm.html)
- [Laravel Documentation](https://laravel.com/docs)
