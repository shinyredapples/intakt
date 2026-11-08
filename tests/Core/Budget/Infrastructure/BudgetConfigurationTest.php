<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Infrastructure\BudgetConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(BudgetConfiguration::class)]
#[UsesClass(UsageWindow::class)]
final class BudgetConfigurationTest extends TestCase
{
    public function testWithoutABudgetSectionEveryWindowHasTheDefaultCeilingOfNinetyPercent(): void
    {
        self::assertSame(['five-hour' => 90.0, 'weekly' => 90.0], $this->ceilings([]));
    }

    public function testTheOwnerSetsTheCeilingOfOneWindowAndTheOtherKeepsTheDefault(): void
    {
        self::assertSame(['five-hour' => 75.0, 'weekly' => 90.0], $this->ceilings(['budget' => ['ceilings' => ['five-hour' => 75]]]));
    }

    public function testWithoutABudgetSectionEveryWindowHasTheDefaultShadowCeilingOfSeventyPercent(): void
    {
        self::assertSame(['five-hour' => 70.0, 'weekly' => 70.0], $this->ceilings([], 'shadow_ceilings'));
    }

    public function testTheOwnerSetsTheShadowCeilingOfOneWindowWithoutMovingTheLiveCeilings(): void
    {
        $contents = ['budget' => ['shadow_ceilings' => ['five-hour' => 60]]];

        self::assertSame(['five-hour' => 60.0, 'weekly' => 70.0], $this->ceilings($contents, 'shadow_ceilings'));
        self::assertSame(['five-hour' => 90.0, 'weekly' => 90.0], $this->ceilings($contents));
    }

    public function testAShadowCeilingOutsideOneToAHundredPercentIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/intakt\.budget\.shadow_ceilings\.five-hour/');

        $this->ceilings(['budget' => ['shadow_ceilings' => ['five-hour' => 0]]], 'shadow_ceilings');
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

    public function testWithoutPricesNoModelHasAPrice(): void
    {
        self::assertSame([], $this->ceilings([], 'prices'));
    }

    public function testAModelsPricesAreReadAsDollarsPerMillionTokensUnderItsUnchangedName(): void
    {
        $prices = $this->ceilings(['budget' => ['prices' => ['claude-opus-5-5' => ['input' => 5, 'output' => 25, 'cache_read' => 0.5, 'cache_write' => 6.25]]]], 'prices');

        self::assertSame(['claude-opus-5-5' => ['input' => 5.0, 'output' => 25.0, 'cache_read' => 0.5, 'cache_write' => 6.25]], $prices);
    }

    public function testCachePricesLeftOutAreTheInputPrice(): void
    {
        $prices = $this->ceilings(['budget' => ['prices' => ['gpt-5' => ['input' => 1.25, 'output' => 10]]]], 'prices');

        self::assertSame(['gpt-5' => ['input' => 1.25, 'output' => 10.0, 'cache_read' => 1.25, 'cache_write' => 1.25]], $prices);
    }

    public function testAPriceWithoutAnOutputPriceIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/output/');

        $this->ceilings(['budget' => ['prices' => ['gpt-5' => ['input' => 1.25]]]], 'prices');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refusedPrices(): iterable
    {
        yield 'negative' => [-1];
        yield 'not a number' => ['cheap'];
        yield 'not a finite number' => [NAN];
        yield 'infinite' => [INF];
    }

    #[DataProvider('refusedPrices')]
    public function testAPriceThatIsNoAmountIsRefused(mixed $price): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/intakt\.budget\.prices\.gpt-5\.input/');

        $this->ceilings(['budget' => ['prices' => ['gpt-5' => ['input' => $price, 'output' => 10]]]], 'prices');
    }

    public function testAFreeModelIsAllowed(): void
    {
        $prices = $this->ceilings(['budget' => ['prices' => ['local-small' => ['input' => 0, 'output' => 0]]]], 'prices');

        self::assertSame(['local-small' => ['input' => 0.0, 'output' => 0.0, 'cache_read' => 0.0, 'cache_write' => 0.0]], $prices);
    }

    /**
     * @param array<string, mixed> $contents
     *
     * @return array<string, mixed>
     */
    private function ceilings(array $contents, string $kind = 'ceilings'): array
    {
        /** @var array{budget: array<string, array<string, mixed>>} $configuration */
        $configuration = new Processor()->processConfiguration(new BudgetConfiguration(), [$contents]);

        return $configuration['budget'][$kind];
    }
}
