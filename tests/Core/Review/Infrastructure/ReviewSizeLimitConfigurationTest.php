<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Infrastructure\ReviewSizeLimitConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

#[CoversClass(ReviewSizeLimitConfiguration::class)]
final class ReviewSizeLimitConfigurationTest extends TestCase
{
    public function testAProjectWithoutTheSettingGetsTodaysLimitOf200000Characters(): void
    {
        self::assertSame(['alpha' => 200000], ReviewSizeLimitConfiguration::limitsOf(['alpha'], []));
    }

    public function testAProjectWithItsOwnLimitKeepsThatValue(): void
    {
        self::assertSame(['alpha' => 350000], ReviewSizeLimitConfiguration::limitsOf(['alpha'], ['alpha' => 350000]));
    }

    public function testALimitOfOneCharacterIsAllowed(): void
    {
        self::assertSame(['alpha' => 1], ReviewSizeLimitConfiguration::limitsOf(['alpha'], ['alpha' => 1]));
    }

    public function testAProjectKeyedByZeroKeepsItsLimit(): void
    {
        self::assertSame([0 => 43], ReviewSizeLimitConfiguration::limitsOf([0], [0 => 43]));
    }

    public function testABooleanNameIsKeyedAsTheProjectsTreeKeysIt(): void
    {
        self::assertSame(1, ReviewSizeLimitConfiguration::keyOf('outer', true));
    }

    public function testAnEntryWithoutAUsableNameIsKeyedByItsOuterKey(): void
    {
        self::assertSame('outer', ReviewSizeLimitConfiguration::keyOf('outer', null));
    }

    #[DataProvider('invalidLimits')]
    public function testAnInvalidLimitIsRefusedWithAPlainMessage(mixed $value): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/review_size_limit\) must be a positive whole number of characters of diff/');

        ReviewSizeLimitConfiguration::limitsOf(['alpha'], ['alpha' => $value]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
        yield 'text' => ['abc'];
        yield 'numeric text' => ['200000'];
        yield 'fraction' => [1.5];
        yield 'list' => [[1]];
        yield 'null' => [null];
        yield 'boolean' => [true];
    }
}
