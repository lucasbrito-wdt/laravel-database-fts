# Release Notes v1.1.0

## 🎉 Nova Versão - Melhoria na Integração com Eloquent

### 📋 Resumo

Esta versão traz melhorias significativas na integração do método `search()` com o Query Builder do Eloquent, tornando-o um verdadeiro scope que preserva todas as configurações de query.

### ✨ Novidades

#### **Preservação Completa das Configurações do Eloquent**

O método `search()` agora funciona como um **scope nativo do Eloquent**, preservando completamente todas as configurações do query builder:

- ✅ `where()`, `whereIn()`, `orWhere()` - preservados
- ✅ `select()`, `selectRaw()` - preservados  
- ✅ `with()`, `withCount()` - preservados
- ✅ `join()`, `leftJoin()` - preservados
- ✅ `groupBy()`, `having()` - preservados
- ✅ `limit()`, `offset()` - preservados

#### **Score de Relevância**

- Agora sempre adiciona `relevance_score` como coluna extra
- Disponível em ambos MySQL e PostgreSQL
- Acesse via `$model->relevance_score` após a busca

#### **Implementação Simplificada**

- Removida lógica complexa de detecção de selects
- Código mais limpo e manutenível
- Melhor performance

### 📝 Exemplos de Uso

```php
// Busca simples
Post::search('termo')->get();

// Com eager loading
Post::search('termo')->with('author', 'comments')->get();

// Com where adicional
Post::where('status', 'published')
    ->search('termo')
    ->where('created_at', '>', now()->subDays(30))
    ->get();

// Com join
Post::join('categories', 'posts.category_id', '=', 'categories.id')
    ->search('termo')
    ->select('posts.*', 'categories.name as category_name')
    ->get();

// Com paginação
Post::search('termo')->paginate(15);

// Acessando o score de relevância
$posts = Post::search('termo')->get();
foreach ($posts as $post) {
    echo "Relevância: {$post->relevance_score}\n";
}
```

### 🔧 Mudanças Técnicas

#### **MySqlDriver**

- Simplificado método `applySearch()`
- Removida lógica complexa de verificação de selects
- Adiciona apenas `table.*` quando não há selects customizados
- Sempre adiciona `relevance_score` via `addSelect()`

#### **PostgresDriver**

- Implementado suporte ao `relevance_score`
- Simplificada lógica de preservação de selects
- Alinhado comportamento com MySqlDriver

### ⚠️ Breaking Changes

**Nenhuma mudança incompatível**. Esta versão é 100% compatível com versões anteriores.

### 🔄 Migração

**Nenhuma migração necessária**. Basta atualizar o pacote:

```bash
composer update lucasbrito-wdt/laravel-database-fts
```

### 🐛 Correções

- Corrigido problema onde selects customizados eram sobrescritos
- Corrigido problema com relacionamentos não funcionando corretamente
- Melhorado tratamento de queries com joins

### 📊 Performance

- Redução de código desnecessário
- Melhor uso de `addSelect()` ao invés de sobrescrever selects
- Queries mais eficientes

### 🙏 Agradecimentos

Obrigado a todos que reportaram issues e sugeriram melhorias!

---

**Versão:** 1.1.0  
**Data:** 08 de Janeiro de 2026  
**Autor:** Lucas Brito  
**Licença:** MIT
