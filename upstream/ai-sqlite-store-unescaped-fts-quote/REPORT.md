# [SQLite Store] A text query term with a double quote breaks the FTS5 query

Filed upstream as a pull request: https://github.com/symfony/ai/pull/2874. Versions: `symfony/ai-sqlite-store` v1.0.0-BETA1, `symfony/ai-store` v1.0.0-BETA1, PHP 8.5.12 with SQLite FTS5.

## What happens

`Store::queryText()` wraps each term in double quotes to make it an FTS5 phrase (`'"'.$term.'"'`) but does not escape a double quote inside the term. A term such as `src/Quote"d.php` ends the phrase early, and the query throws:

```
a search term with a double quote breaks the FTS5 query: SQLSTATE[HY000]: General error: 1 fts5: syntax error near "."
```

A hybrid query runs the same text query, so `Retriever::retrieve()` on this store throws for any query text with a double quote. A term can also leave its phrase on purpose: `alpha" OR "secret` matches a document that contains only `secret`.

A NUL byte in a term fails too, even with the quote escaped: FTS5 ends the query text at the NUL, the phrase stays open, and the query throws `fts5: unterminated string` (checked on 2026-11-03 with the quote-only fix).

The query is a bound parameter, so SQL injection is not possible. With the quote doubled and the NUL dropped, `OR`/`AND`/`NOT`/`NEAR(...)`, parentheses, column filters (`content:secret`), `sec*`, `^alpha`, `-alpha`, an empty term and a lone `"` are all inert (checked on 2026-11-03).

## Expected

A quote inside a term is escaped as two quotes (`""`), as FTS5 phrases require, a NUL byte is dropped (the tokenizer never indexes it), and `reproduce.php` finds the document for both terms.

## Effect in Intakt

`src/Core/Memory/Infrastructure/StoreProjectMemory.php` replaces double quotes in the query text with spaces before it reaches the `Retriever`; the FTS5 tokenizer drops quotes anyway, so the text search loses nothing. Its query text is a list of file paths, which cannot hold a NUL byte.

## Upstream status

Checked on 2026-11-02; filed and updated on 2026-11-03.

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/ai-*` v1.0.0-BETA1: `still fails  ai-sqlite-store-unescaped-fts-quote`.
- **Development branch:** still fails. The same run in a scratch copy with every `symfony/ai-*` package at `main` (`symfony/ai-sqlite-store` 295e2b27233c, `symfony/ai-store` 71ecd035324e): `still fails  ai-sqlite-store-unescaped-fts-quote`, with the same FTS5 syntax error.
- **Existing issue or pull request:** none found. Candidates in `symfony/ai`, none the same bug: #1770 (closed pull request that added the SQLite store), #1787 (closed pull request, Reciprocal Rank Fusion for hybrid queries), #2112 (closed issue, a sqlite-vec adapter).
- **Searches** (`symfony/ai` issues and pull requests, open and closed): `label:Store SQLite FTS5`, `sqlite queryText`, `fts5 quote`.
- **Decision:** `filed as pull request`: https://github.com/symfony/ai/pull/2874 (opened 2026-11-03 from the owner's fork, one commit, the prepared `fix.patch` and `PR.md` unchanged apart from the commit body wrapped at 72 characters), merged 2026-11-03; no `symfony/ai` release after v1.0.0-BETA1 carries it yet (recorded 2026-11-20). Checked 2026-11-22: no `symfony/ai` release after v1.0.0-BETA1 yet (the newest tag is still v1.0.0-BETA1).
- **VecStore evidence (#696):** with sqlite-vec v0.1.9 loaded, the `VecStoreTest` class alone is OK with 36 tests and no skips (a run of that one class, not the package suite); the three new tests fail with the `VecStore.php` change reverted.
- **When a release fixes it:** once a later `intakt:upstream:run` finds the reproduction passing on a Symfony AI release, this section records that release.
- **Pull request search (D12, 2026-11-03, `main` 17d320e7beee):** open with the prefix `[Store][Sqlite]`: none; open with `[Store]` or `[Platform][Store]`: #2865, #2864, #2805, #2720, #2707, #2367, #2106, #1901, #1613 and #2872, none about SQLite full-text search; open touching `src/store/src/Bridge/Sqlite/`: #2805 and #2865 (`Store.php`), #2835 (`[AI Bundle][Store] Accept a DSN in the PDO-based stores`: `Store.php`, `VecStore.php`, `StoreFactory.php`), none changes `queryText()`; merged since BETA1 on that path: none.
- **Fix pull request:** filed as symfony/ai#2874. One package, FTS5 requires a doubled quote inside a phrase, and the package suite tests it. Prepared on `symfony/ai` `main` 17d320e7beee as `fix.patch` and `PR.md`; the same expression in `VecStore` is fixed too. A term containing a double quote that used to run as part of an FTS5 query expression is now matched as one literal phrase; this change is intended, since a term is meant to be a phrase. A term with neither a double quote nor a NUL byte produces the same query as before. Refreshed on 2026-11-03: `main` was still 17d320e7beee, the patch now also drops NUL bytes and tests a term that tries to leave its phrase, and the package suite passes with it (96 tests across all of the package's test classes, 45 of them skipped without the sqlite-vec extension; the 45 is the suite's skip count for every test that needs sqlite-vec, not the size of `VecStoreTest`, which the VecStore evidence above counts on its own). The other stores that take a `TextQuery` or `HybridQuery` on that commit are not affected: Postgres binds each term to `plainto_tsquery`, which ignores operators; ChromaDb embeds the texts; Cache compares in PHP; Meilisearch sends the text as the `q` value of its search request. Open pull requests (checked 2026-11-03): none fixes it; #2805 and #2865 edit `Store.php` (HTTP options, the `limit` option) but not `queryText()`, and nothing merged since BETA1 touched the package. Rebase symfony/ai#2874 if one of them merges first.
