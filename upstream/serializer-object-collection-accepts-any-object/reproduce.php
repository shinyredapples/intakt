<?php

declare(strict_types=1);

use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final readonly class Item
{
    public function __construct(
        public string $name,
    ) {
    }
}

final readonly class Basket
{
    /**
     * @param list<Item> $items
     */
    public function __construct(
        public array $items,
    ) {
    }
}

$types = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);
$serializer = new Serializer([new ArrayDenormalizer(), new ObjectNormalizer(propertyTypeExtractor: $types)], [new JsonEncoder()]);

try {
    $serializer->deserialize('{"items": {}}', Basket::class, JsonEncoder::FORMAT,[JsonDecode::ASSOCIATIVE => false]);
    echo 'An object was accepted as a list of Item', PHP_EOL;

    exit(1);
} catch (NotNormalizableValueException $exception) {
    echo $exception->getMessage(), PHP_EOL;
} catch (TypeError $error) {
    echo 'TypeError instead of NotNormalizableValueException: ', $error->getMessage(), PHP_EOL;

    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
