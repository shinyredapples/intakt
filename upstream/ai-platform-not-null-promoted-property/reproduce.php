<?php

declare(strict_types=1);

use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;
use Symfony\Component\Validator\Constraints\NotNull;

final readonly class Named
{
    public function __construct(
        #[NotNull]
        public ?string $title = null,
    ) {
    }
}

$title = new ResponseFormatFactory()->create(Named::class)['json_schema']['schema']['properties']['title'];
echo json_encode($title), PHP_EOL;

// NotNull means the value must not be null, so the schema sent to the model must not allow it either.
if (in_array('null', (array) ($title['type'] ?? []), true)) {
    echo 'the type still allows null', PHP_EOL;

    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
