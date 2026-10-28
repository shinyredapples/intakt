<?php

declare(strict_types=1);

use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;
use Symfony\Component\Validator\Constraints\Positive;

final readonly class Place
{
    public function __construct(
        #[Positive]
        public int $line,
    ) {
    }
}

$line = new ResponseFormatFactory()->create(Place::class)['json_schema']['schema']['properties']['line'];
echo json_encode($line), PHP_EOL;

// Structured output providers speak JSON Schema 2020-12, where exclusiveMinimum is a number, never a boolean.
if (!is_int($line['exclusiveMinimum'] ?? null) && !is_float($line['exclusiveMinimum'] ?? null)) {
    echo 'exclusiveMinimum is not a number', PHP_EOL;

    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
