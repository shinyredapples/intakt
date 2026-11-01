<?php

declare(strict_types=1);

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Bridge\Sqlite\Store;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Query\TextQuery;

$store = Store::fromPdo(new PDO('sqlite::memory:'), 'documents');
$store->setup();
$store->add(new VectorDocument('one', new Vector([1.0, 0.0]), new Metadata([Metadata::KEY_TEXT => 'src/Quote"d.php'])));

try {
    $found = iterator_to_array($store->query(new TextQuery(['src/Quote"d.php'])));
} catch (PDOException $exception) {
    echo 'a search term with a double quote breaks the FTS5 query: ', $exception->getMessage(), PHP_EOL;

    exit(1);
}

// The term is a phrase, so a quote inside it should be escaped as two quotes and the document found.
if (1 !== count($found)) {
    echo sprintf('the document was not found (%d results)', count($found)), PHP_EOL;

    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
