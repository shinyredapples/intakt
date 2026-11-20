<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\FindingIds;
use Intakt\Core\Review\Infrastructure\StoredFindingIdsNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Serializer\Exception\LogicException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;

#[CoversClass(StoredFindingIdsNormalizer::class)]
#[UsesClass(FindingIds::class)]
final class StoredFindingIdsNormalizerTest extends TestCase
{
    public function testFindingIdsAreWrittenAsAList(): void
    {
        self::assertSame(['F1', 'F2'], new StoredFindingIdsNormalizer()->normalize(new FindingIds('F1', 'F2')));
    }

    public function testNoFindingIdsAreWrittenAsAnEmptyList(): void
    {
        self::assertSame([], new StoredFindingIdsNormalizer()->normalize(new FindingIds()));
    }

    public function testAnyOtherValueIsNotNormalized(): void
    {
        $this->expectException(LogicException::class);

        new StoredFindingIdsNormalizer()->normalize(new stdClass());
    }

    public function testFindingIdsAreSupportedForWriting(): void
    {
        self::assertTrue(new StoredFindingIdsNormalizer()->supportsNormalization(new FindingIds()));
    }

    public function testAPlainListIsNotSupportedForWriting(): void
    {
        self::assertFalse(new StoredFindingIdsNormalizer()->supportsNormalization(['F1']));
    }

    public function testAListOfStringsIsReadAsFindingIds(): void
    {
        $ids = new StoredFindingIdsNormalizer()->denormalize(['F1', 'F2'], FindingIds::class);

        self::assertInstanceOf(FindingIds::class, $ids);
        self::assertSame(['F1', 'F2'], [...$ids]);
    }

    public function testStringsUnderKeysAreReadAsFindingIdsInTheirOrder(): void
    {
        $ids = new StoredFindingIdsNormalizer()->denormalize([1 => 'F1', 'a' => 'F2'], FindingIds::class);

        self::assertInstanceOf(FindingIds::class, $ids);
        self::assertSame(['F1', 'F2'], [...$ids]);
    }

    public function testStringsUnderKeysAreWrittenAgainUnderTheSameKeys(): void
    {
        $normalizer = new StoredFindingIdsNormalizer();
        $ids = $normalizer->denormalize([1 => 'F1', 'a' => 'F2'], FindingIds::class);

        self::assertInstanceOf(FindingIds::class, $ids);
        self::assertSame([1 => 'F1', 'a' => 'F2'], $normalizer->normalize($ids));
    }

    public function testAValueThatIsNoArrayFailsToRead(): void
    {
        $this->expectException(UnexpectedValueException::class);

        new StoredFindingIdsNormalizer()->denormalize('F1', FindingIds::class);
    }

    public function testAnEntryThatIsNoStringFailsToRead(): void
    {
        $this->expectException(UnexpectedValueException::class);

        new StoredFindingIdsNormalizer()->denormalize(['F1', 3], FindingIds::class);
    }

    public function testFindingIdsAreSupportedForReading(): void
    {
        self::assertTrue(new StoredFindingIdsNormalizer()->supportsDenormalization([], FindingIds::class));
    }

    public function testAnyOtherTypeIsNotSupportedForReading(): void
    {
        self::assertFalse(new StoredFindingIdsNormalizer()->supportsDenormalization([], stdClass::class));
    }

    public function testFindingIdsAreNamedAsCacheable(): void
    {
        self::assertSame([FindingIds::class => true], new StoredFindingIdsNormalizer()->getSupportedTypes(null));
    }
}
