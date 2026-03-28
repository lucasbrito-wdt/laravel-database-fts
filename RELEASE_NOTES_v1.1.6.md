# Release Notes v1.1.6

## searchOrderBy — Ordenação por Relevância via Relacionamento

### Resumo

Adiciona suporte nativo a ordenação por relevância quando `search()` é usado dentro de `whereHas()`. A lógica que antes precisava ser escrita manualmente no serviço agora faz parte do core do pacote.

### Novidades

#### `Contact::searchOrderBy($outerQuery, $foreignKey, $term)` (Searchable trait)

Novo método estático disponível em qualquer model que use o trait `Searchable`. Aplica `ORDER BY similarity(...)` no query externo usando uma subquery correlacionada, permitindo que os resultados sejam ordenados pela relevância do relacionamento pesquisado.

```php
// Antes: SQL hardcoded no serviço
$query->whereHas('contact', fn($q) => $q->search($term));
$query->orderByRaw(
    "similarity((SELECT name FROM contacts WHERE id = conversations.contact_id), ?) DESC",
    [$term]
);

// Agora: core do pacote cuida da ordenação
$query->whereHas('contact', fn($q) => $q->search($term));
Contact::searchOrderBy($query, 'conversations.contact_id', $term);
```

#### `applyRelationSearchOrder()` (DriverInterface + Drivers)

Novo método na interface `DriverInterface`, implementado em ambos os drivers:

- **PostgresDriver**: usa `similarity()` com subquery correlacionada quando `pg_trgm` está disponível. Sem-op quando a extensão não está presente.
- **MySqlDriver**: sem-op (MySQL FULLTEXT não suporta score via subquery correlacionada de forma eficiente).

### Exemplos de Uso

```php
// Conversas ordenadas pela similaridade do nome do contato
$query->whereHas('contact', function ($q) use ($term) {
    $q->search($term);
});
Contact::searchOrderBy($query, 'conversations.contact_id', $term);

// A ordenação por relevância precede qualquer outra ordenação definida
$query->orderBy('last_message_at', 'desc'); // aplicado após relevance
```

### Mudanças Técnicas

#### DriverInterface
- Adicionado método `applyRelationSearchOrder(Builder $outerQuery, array $columns, string $relatedTable, string $foreignKeyExpression, string $term): Builder`

#### PostgresDriver
- Implementado `applyRelationSearchOrder()` com subquery correlacionada e `similarity()`
- Fallback sem-op quando `pg_trgm` não está disponível

#### MySqlDriver
- Implementado `applyRelationSearchOrder()` como sem-op

#### Searchable (trait)
- Adicionado método estático `searchOrderBy(Builder $outerQuery, string $foreignKeyExpression, string $term): Builder`

### Breaking Changes

**Nenhuma mudança incompatível.** Esta versão é 100% compatível com versões anteriores.

### Migração

Nenhuma migração necessária. Basta atualizar o pacote:

```bash
composer update lucasbrito-wdt/laravel-database-fts
```

---

**Versão:** 1.1.6
**Data:** 27 de Março de 2026
**Autor:** Lucas Brito
**Licença:** MIT
