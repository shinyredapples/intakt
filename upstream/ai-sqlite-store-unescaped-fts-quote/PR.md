[Store][Sqlite] Escape double quotes in full-text search terms

| Q             | A
| ------------- | ---
| Bug fix?      | yes
| New feature?  | no
| Docs?         | no
| Issues        | -
| License       | MIT

`Store::queryText()` and `VecStore::queryText()` wrap each term in double quotes to make it an FTS5 phrase, but a double quote inside the term ended the phrase early. A term such as `src/Quote"d.php` made the query fail with `fts5: syntax error near "."`, and because a hybrid query runs the same text query, `Retriever::retrieve()` threw for any query text with a double quote.

The change doubles a quote inside a term (`"` becomes `""`), which is how FTS5 writes a quote inside a phrase. Terms without a quote produce the same query as before.

Tests: `StoreTest::testQueryTextEscapesDoubleQuotesInTerms` indexes a document that mentions `src/Quote"d.php`, queries for that path and expects the document (it fails with the syntax error above without the change). `VecStoreTest` gets the same test, skipped where the sqlite-vec extension is missing, as its other tests are.

Reproduction:

```php
$pdo = new \PDO('sqlite::memory:');
$store = new Store($pdo, 'docs');
$store->setup();

$metadata = new Metadata();
$metadata->setText('The file src/Quote"d.php is edited');
$store->add(new VectorDocument('doc-1', new Vector([0.1, 0.2, 0.3]), $metadata));

iterator_to_array($store->query(new TextQuery('src/Quote"d.php'))); // throws before, finds doc-1 after
```

Out of scope: the open pull requests #2805 and #2865 also edit `Store.php` (HTTP options, the `limit` option); neither touches `queryText()`, so the two should not conflict.
