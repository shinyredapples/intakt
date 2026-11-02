<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Infrastructure\CeilingsConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(CeilingsConfiguration::class)]
#[UsesClass(UsageWindow::class)]
final class CeilingsConfigurationTest extends TestCase
{
    public function testWithoutABudgetSectionEveryWindowHasTheDefaultCeilingOfNinetyPercent(): void
    {
        self::assertSame(['five-hour' => 90.0, 'weekly' => 90.0], $this->ceilings([]));
    }

    public function testTheOwnerSetsTheCeilingOfOneWindowAndTheOtherKeepsTheDefault(): void
    {
        self::assertSame(['five-hour' => 75.0, 'weekly' => 90.0], $this->ceilings(['budget' => ['ceilings' => ['five-hour' => 75]]]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refusedCeilings(): iterable
    {
        yield 'above a hundred' => [101];
        yield 'below one' => [0.5];
        yield 'not a number' => ['high'];
        yield 'not a finite number' => [NAN];
        yield 'infinite' => [INF];
    }

    #[DataProvider('refusedCeilings')]
    public function testACeilingOutsideOneToAHundredPercentIsRefused(mixed $ceiling): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/intakt\.budget\.ceilings\.weekly/');

        $this->ceilings(['budget' => ['ceilings' => ['weekly' => $ceiling]]]);
    }

    public function testAWindowIntaktDoesNotKnowIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/five_hour/');

        $this->ceilings(['budget' => ['ceilings' => ['five_hour' => 80]]]);
    }

    /**
     * @param array<string, mixed> $contents
     *
     * @return array<string, float>
     */
    private function ceilings(array $contents): array
    {
        /** @var array{budget: array{ceilings: array<string, float>}} $configuration */
        $configuration = new Processor()->processConfiguration(new CeilingsConfiguration(), [$contents]);

        return $configuration['budget']['ceilings'];
    }
}
