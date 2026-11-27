<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\FindingIds;
use Intakt\Core\Review\Contract\SameCause;
use Intakt\Core\Review\Infrastructure\StoredFindingIdsNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Serializer\Exception\LogicException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;

#[CoversClass(StoredFindingIdsNormalizer::class)]
#[UsesClass(FindingIds::class)]
#[UsesClass(SameCause::class)]
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

    public function testLinksAreWrittenAsAMapFromFindingToCause(): void
    {
        self::assertSame(['F3' => 'F1'], new StoredFindingIdsNormalizer()->normalize(new SameCause()->with('F3', 'F1')));
    }

    public function testNoLinksAreWrittenAsAnEmptyArray(): void
    {
        self::assertSame([], new StoredFindingIdsNormalizer()->normalize(new SameCause()));
    }

    public function testAnyOtherValueIsNotNormalized(): void
    {
        $this->expectException(LogicException::class);

        new StoredFindingIdsNormalizer()->normalize(new stdClass());
    }

    public function testFindingIdsAndLinksAreSupportedForWriting(): void
    {
        $normalizer = new StoredFindingIdsNormalizer();

        self::assertSame([true, true], [$normalizer->supportsNormalization(new FindingIds()), $normalizer->supportsNormalization(new SameCause())]);
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

    public function testAKeyedListIsReadInOrderAndWrittenBackAsAList(): void
    {
        $normalizer = new StoredFindingIdsNormalizer();
        $ids = $normalizer->denormalize([1 => 'F3', 'a' => 'F1'], FindingIds::class);

        self::assertInstanceOf(FindingIds::class, $ids);
        self::assertSame(['F3', 'F1'], $normalizer->normalize($ids));
    }

    public function testAMapOfStringsIsReadAsLinks(): void
    {
        $links = new StoredFindingIdsNormalizer()->denormalize(['F3' => 'F1', 'F4' => 'F1'], SameCause::class);

        self::assertInstanceOf(SameCause::class, $links);
        self::assertSame(['F3' => 'F1', 'F4' => 'F1'], iterator_to_array($links));
    }

    public function testAnEmptyArrayIsReadAsNoLinks(): void
    {
        $links = new StoredFindingIdsNormalizer()->denormalize([], SameCause::class);

        self::assertInstanceOf(SameCause::class, $links);
        self::assertSame([], iterator_to_array($links));
    }

    public function testANumericKeyIsReadAsTheFindingIdItSpells(): void
    {
        $links = new StoredFindingIdsNormalizer()->denormalize([7 => 'F1'], SameCause::class);

        self::assertInstanceOf(SameCause::class, $links);
        self::assertSame('F1', $links->causeOf('7'));
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

    public function testLinksThatAreNoArrayFailToRead(): void
    {
        $this->expectException(UnexpectedValueException::class);

        new StoredFindingIdsNormalizer()->denormalize('F1', SameCause::class);
    }

    public function testALinkWhoseCauseIsNoStringFailsToRead(): void
    {
        $this->expectException(UnexpectedValueException::class);

        new StoredFindingIdsNormalizer()->denormalize(['F2' => 3], SameCause::class);
    }

    public function testAStoredLinkOfAFindingToItselfStillReadsBack(): void
    {
        $read = new StoredFindingIdsNormalizer()->denormalize(['F2' => 'F2'], SameCause::class);

        self::assertInstanceOf(SameCause::class, $read);
        self::assertSame('F2', $read->causeOf('F2'));
    }

    public function testAStoredLinkWithAnEmptyCauseFailsToRead(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/holds a link it refuses: A same-cause link needs a finding id and a cause id\./');

        new StoredFindingIdsNormalizer()->denormalize(['F2' => ''], SameCause::class);
    }

    public function testFindingIdsAndLinksAreSupportedForReading(): void
    {
        $normalizer = new StoredFindingIdsNormalizer();

        self::assertSame([true, true], [$normalizer->supportsDenormalization([], FindingIds::class), $normalizer->supportsDenormalization([], SameCause::class)]);
    }

    public function testAnyOtherTypeIsNotSupportedForReading(): void
    {
        self::assertFalse(new StoredFindingIdsNormalizer()->supportsDenormalization([], stdClass::class));
    }

    public function testBothTypesAreNamedAsCacheable(): void
    {
        self::assertSame([FindingIds::class => true, SameCause::class => true], new StoredFindingIdsNormalizer()->getSupportedTypes(null));
    }
}
