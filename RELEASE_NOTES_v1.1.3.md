# Release Notes v1.1.3

## Melhoria na Resiliência da Busca PostgreSQL

### Resumo

Esta versão adiciona detecção automática da extensão `pg_trgm` no PostgreSQL e fallback para `LIKE` quando ela não estiver disponível, tornando o pacote mais robusto em ambientes sem a extensão instalada.

### Novidades

#### Fallback Automático para LIKE (PostgresDriver)

O `PostgresDriver` agora verifica se a extensão `pg_trgm` está instalada antes de utilizar `similarity()`. Quando a extensão não estiver disponível, a busca cai automaticamente para `ILIKE`:

- Ambientes com `pg_trgm` — comportamento idêntico às versões anteriores (similarity + relevance_score)
- Ambientes sem `pg_trgm` — busca por `ILIKE '%termo%'` com `relevance_score = 1`

#### Verificação via pg_extension

A detecção é feita consultando `pg_extension` diretamente no banco, sem tentar executar a query e capturar o erro:

```php
SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm'
```

### Exemplos de Uso

```php
// Funciona com ou sem pg_trgm instalado
Post::search('termo')->get();

// Com pg_trgm: ordena por relevância
$posts = Post::search('termo')->get();
foreach ($posts as $post) {
    echo "Relevância: {$post->relevance_score}\n"; // 0.0 a 1.0
}

// Sem pg_trgm: relevance_score = 1, busca por ILIKE
$posts = Post::search('termo')->get();
foreach ($posts as $post) {
    echo "Relevância: {$post->relevance_score}\n"; // sempre 1
}
```

### Mudanças Técnicas

#### PostgresDriver

- Adicionado método `hasTrgmExtension()` para verificar disponibilidade da extensão
- `applySearch()` usa similarity quando pg_trgm está disponível, ILIKE como fallback
- Nenhuma alteração na interface pública

### Breaking Changes

**Nenhuma mudança incompatível.** Esta versão é 100% compatível com versões anteriores.

### Migração

Nenhuma migração necessária. Basta atualizar o pacote:

```bash
composer update lucasbrito-wdt/laravel-database-fts
```

### Correções

- Corrigido tratamento de termos de busca curtos no PostgresDriver

---

**Versão:** 1.1.3
**Data:** 27 de Março de 2026
**Autor:** Lucas Brito
**Licença:** MIT
