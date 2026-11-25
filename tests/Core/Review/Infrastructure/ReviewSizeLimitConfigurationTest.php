<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Infrastructure\ReviewSizeLimitConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(ReviewSizeLimitConfiguration::class)]
final class ReviewSizeLimitConfigurationTest extends TestCase
{
    public function testAProjectWithoutTheSettingGetsTodaysLimitOf200000Characters(): void
    {
        self::assertSame(['alpha' => 200000], ReviewSizeLimitConfiguration::limitsOf($this->processed([])));
    }

    public function testAProjectWithItsOwnLimitKeepsThatValue(): void
    {
        self::assertSame(['alpha' => 350000], ReviewSizeLimitConfiguration::limitsOf($this->processed(['review_size_limit' => 350000])));
    }

    public function testALimitOfOneCharacterIsAllowed(): void
    {
        self::assertSame(['alpha' => 1], ReviewSizeLimitConfiguration::limitsOf($this->processed(['review_size_limit' => 1])));
    }

    #[DataProvider('invalidLimits')]
    public function testAnInvalidLimitIsRefusedWithAPlainMessage(mixed $value): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/review_size_limit\) must be a positive whole number of characters of diff/');

        $this->processed(['review_size_limit' => $value]);
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

    /**
     * @param array<string, mixed> $section
     *
     * @return array<string, array{review_size_limit: int}>
     */
    private function processed(array $section): array
    {
        /** @var array{projects: array<string, array{review_size_limit: int}>} $configuration */
        $configuration = new Processor()->processConfiguration(new ReviewSizeLimitConfiguration(), [['projects' => ['alpha' => $section]]]);

        return $configuration['projects'];
    }
}
