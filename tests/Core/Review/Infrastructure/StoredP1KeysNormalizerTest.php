<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\P1Keys;
use Intakt\Core\Review\Infrastructure\StoredP1KeysNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Serializer\Exception\LogicException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;

#[CoversClass(StoredP1KeysNormalizer::class)]
#[UsesClass(P1Keys::class)]
final class StoredP1KeysNormalizerTest extends TestCase
{
    public function testKeysAreWrittenAsAMapFromFindingToKeyWithNullForAFindingWithoutOne(): void
    {
        self::assertSame(['F1' => 'abc', 'F2' => null], new StoredP1KeysNormalizer()->normalize(new P1Keys()->with('F1', 'abc')->with('F2', null)));
    }

    public function testNoKeysAreWrittenAsAnEmptyArray(): void
    {
        self::assertSame([], new StoredP1KeysNormalizer()->normalize(new P1Keys()));
    }

    public function testAnyOtherValueIsNotNormalized(): void
    {
        $this->expectException(LogicException::class);

        new StoredP1KeysNormalizer()->normalize(new stdClass());
    }

    public function testKeysAreSupportedForWritingAndAPlainMapIsNot(): void
    {
        $normalizer = new StoredP1KeysNormalizer();

        self::assertSame([true, false], [$normalizer->supportsNormalization(new P1Keys()), $normalizer->supportsNormalization(['F1' => 'abc'])]);
    }

    public function testAMapOfStringsAndNullsIsReadAsKeys(): void
    {
        $keys = new StoredP1KeysNormalizer()->denormalize(['F1' => 'abc', 'F2' => null], P1Keys::class);

        self::assertInstanceOf(P1Keys::class, $keys);
        self::assertSame(['F1' => 'abc', 'F2' => null], iterator_to_array($keys));
    }

    public function testAnEmptyArrayIsReadAsNoKeys(): void
    {
        $keys = new StoredP1KeysNormalizer()->denormalize([], P1Keys::class);

        self::assertInstanceOf(P1Keys::class, $keys);
        self::assertSame([], iterator_to_array($keys));
    }

    public function testANumericKeyIsReadAsTheFindingIdItSpells(): void
    {
        $keys = new StoredP1KeysNormalizer()->denormalize([7 => 'abc'], P1Keys::class);

        self::assertInstanceOf(P1Keys::class, $keys);
        self::assertSame('abc', $keys->keyOf('7'));
    }

    public function testKeysThatAreNoArrayFailToRead(): void
    {
        $this->expectException(UnexpectedValueException::class);

        new StoredP1KeysNormalizer()->denormalize('abc', P1Keys::class);
    }

    public function testAKeyThatIsNeitherAStringNorNullFailsToRead(): void
    {
        $this->expectException(UnexpectedValueException::class);

        new StoredP1KeysNormalizer()->denormalize(['F1' => 3], P1Keys::class);
    }

    public function testOnlyKeysAreSupportedForReading(): void
    {
        $normalizer = new StoredP1KeysNormalizer();

        self::assertSame([true, false], [$normalizer->supportsDenormalization([], P1Keys::class), $normalizer->supportsDenormalization([], stdClass::class)]);
    }

    public function testKeysAreNamedAsCacheable(): void
    {
        self::assertSame([P1Keys::class => true], new StoredP1KeysNormalizer()->getSupportedTypes(null));
    }
}
