<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Intakt\Core\Review\Infrastructure\StoredFindingIdsNormalizer;
use Intakt\Core\Review\Infrastructure\StoredFindingNormalizer;
use Intakt\Core\Review\Infrastructure\StoredJsonEncoder;
use Intakt\Core\Review\Infrastructure\StoredP1KeysNormalizer;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

// Built by hand because these tests make a ledger writer or reader without the container; it mirrors the `stored` serializer of config/packages/framework.yaml, which StoredSerializerFactoryTest pins.
final class StoredSerializerFactory
{
    public static function create(): SerializerInterface
    {
        $types = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);
        $objects = new ObjectNormalizer(
            classMetadataFactory: new ClassMetadataFactory(new AttributeLoader()),
            nameConverter: new CamelCaseToSnakeCaseNameConverter(),
            propertyTypeExtractor: $types,
        );

        return new Serializer([new DateTimeNormalizer(), new BackedEnumNormalizer(), new ArrayDenormalizer(), new StoredFindingNormalizer(), new StoredFindingIdsNormalizer(), new StoredP1KeysNormalizer(), $objects], [new StoredJsonEncoder(), new JsonEncoder()]);
    }
}
