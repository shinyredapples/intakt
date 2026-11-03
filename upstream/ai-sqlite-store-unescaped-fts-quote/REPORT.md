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

## Upstream status

Checked on 2026-11-02.

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/ai-*` v1.0.0-BETA1: `still fails  ai-sqlite-store-unescaped-fts-quote`.
- **Development branch:** still fails. The same run in a scratch copy with every `symfony/ai-*` package at `main` (`symfony/ai-sqlite-store` 295e2b27233c, `symfony/ai-store` 71ecd035324e): `still fails  ai-sqlite-store-unescaped-fts-quote`, with the same FTS5 syntax error.
- **Existing issue or pull request:** none found. Candidates in `symfony/ai`, none the same bug: #1770 (closed pull request that added the SQLite store), #1787 (closed pull request, Reciprocal Rank Fusion for hybrid queries), #2112 (closed issue, a sqlite-vec adapter).
- **Searches** (`symfony/ai` issues and pull requests, open and closed): `label:Store SQLite FTS5`, `sqlite queryText`, `fts5 quote`.
- **Decision:** `ready to file`.
