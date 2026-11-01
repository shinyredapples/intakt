# [SQLite Store] A text query term with a double quote breaks the FTS5 query

Draft, not posted. Versions: `symfony/ai-sqlite-store` v1.0.0-BETA1, `symfony/ai-store` v1.0.0-BETA1, PHP 8.5.12 with SQLite FTS5.

## What happens

`Store::queryText()` wraps each term in double quotes to make it an FTS5 phrase (`'"'.$term.'"'`) but does not escape a double quote inside the term. A term such as `src/Quote"d.php` ends the phrase early, and the query throws:

```
a search term with a double quote breaks the FTS5 query: SQLSTATE[HY000]: General error: 1 fts5: syntax error near "."
```

A hybrid query runs the same text query, so `Retriever::retrieve()` on this store throws for any query text with a double quote.

## Expected

A quote inside a term is escaped as two quotes (`""`), as FTS5 phrases require, and `reproduce.php` finds the document.

## Effect in Intakt

`src/Core/Memory/Infrastructure/StoreProjectMemory.php` replaces double quotes in the query text with spaces before it reaches the `Retriever`; the FTS5 tokenizer drops quotes anyway, so the text search loses nothing.
