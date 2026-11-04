<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

// The `stored` serializer of config/packages/framework.yaml for tests that build a ledger writer without the container.
final class StoredSerializerFactory
{
    public static function create(): SerializerInterface
    {
        $types = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);
        $objects = new ObjectNormalizer(nameConverter: new CamelCaseToSnakeCaseNameConverter(), propertyTypeExtractor: $types);

        return new Serializer([new DateTimeNormalizer(), new BackedEnumNormalizer(), $objects], [new JsonEncoder()]);
    }
}
