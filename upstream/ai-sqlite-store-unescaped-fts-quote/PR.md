Commit:

```
[Store][Sqlite] Escape full-text search terms

Both stores wrap each text query term in double quotes to make it an FTS5 phrase, but a double quote inside the term ended the phrase early, so the term could add FTS5 syntax or make the query fail. A NUL byte ended the query text and left the phrase unterminated. A quote is now doubled, as FTS5 phrases require, and a NUL byte is dropped, so a term is always one phrase.
```

---

[Store][Sqlite] Escape full-text search terms

| Q             | A
| ------------- | ---
| Bug fix?      | yes
| New feature?  | no
| Docs?         | no
| Issues        | -
| License       | MIT

`Store::queryText()` and `VecStore::queryText()` wrap each term in double quotes to make it an FTS5 phrase, but a double quote inside the term ended the phrase early. A term such as `src/Quote"d.php` made the query fail with `fts5: syntax error near "."`, and `alpha" OR "secret` matched a document that contains only `secret`. A NUL byte failed too: FTS5 ends the query text at the NUL and raises `unterminated string`. Because a hybrid query runs the same text query, `Retriever::retrieve()` threw for such text.

The change doubles a quote inside a term (`"` becomes `""`), which is how FTS5 writes a quote inside a phrase, and drops NUL bytes, which the tokenizer never indexes. A term can then never change the FTS5 query; terms without either character produce the same query as before.

This matters because an agent tool can pass model-chosen text into a store search, so a quote or NUL byte from a prompt-injected model breaks the search. The query is a bound parameter, so SQL injection was never possible. The other stores that take text queries are not affected: Postgres binds each term to `plainto_tsquery`, which ignores operators; ChromaDb embeds the texts; Cache compares in PHP.

Tests, in `StoreTest` and `VecStoreTest` (the latter skipped where the sqlite-vec extension is missing, as its other tests are):

- `testQueryTextEscapesDoubleQuotesInTerms`: a query for `src/Quote"d.php` finds the document that mentions it.
- `testQueryTextIgnoresNulBytesInTerms`: a query for `fo\0x` finds the document `fox` finds, and raises nothing.
- `testQueryTextKeepsQuotedOperatorsInsideThePhrase`: `alpha" OR "secret` does not match a document that contains only `secret`.

The three `StoreTest` tests fail without the change. The `VecStoreTest` copies were not run without it, because the sqlite-vec extension was not available.

Reproduction:

```php
$pdo = new \PDO('sqlite::memory:');
$store = new Store($pdo, 'docs');
$store->setup();

$metadata = new Metadata();
$metadata->setText('The file src/Quote"d.php is edited');
$store->add(new VectorDocument('doc-1', new Vector([0.1, 0.2, 0.3]), $metadata));

iterator_to_array($store->query(new TextQuery('src/Quote"d.php'))); // throws before, finds doc-1 after
iterator_to_array($store->query(new TextQuery("src/Quo\0te\"d.php"))); // throws before, finds doc-1 after
```

Out of scope: the open pull requests #2805 and #2865 also edit `Store.php` (HTTP options, the `limit` option), and #2835 edits both stores (a DSN in the PDO-based stores); none touches `queryText()`, so they should not conflict.
