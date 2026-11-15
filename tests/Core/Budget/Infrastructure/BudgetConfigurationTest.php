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
use Symfony\Component\Yaml\Yaml;

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

    public function testTheOwnerNamesTheDayTheyReadThePrices(): void
    {
        self::assertSame('2026-11-13', $this->priceDate(['budget' => ['price_date' => '2026-11-13']]));
    }

    public function testAQuotedPriceDateInYamlIsTheDayWritten(): void
    {
        $contents = Yaml::parse("budget:\n    price_date: '2026-11-13'\n");
        self::assertIsArray($contents);

        self::assertSame('2026-11-13', $this->priceDate($contents));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unquotedDates(): iterable
    {
        yield 'a day that exists' => ['2026-11-13'];
        yield 'a day that does not exist, which YAML moves to 2026-03-02' => ['2026-02-30'];
    }

    // Symfony YAML reads an unquoted date as a timestamp and moves an impossible day into the next month, so the day written is lost before it can be checked.
    #[DataProvider('unquotedDates')]
    public function testAnUnquotedPriceDateInYamlIsRefusedWithAHintToQuoteIt(string $date): void
    {
        $contents = Yaml::parse("budget:\n    price_date: ".$date."\n");
        self::assertIsArray($contents);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/price_date must be a date written as YYYY-MM-DD in quotes/');

        $this->priceDate($contents);
    }

    public function testWithoutAPriceDateItIsNull(): void
    {
        self::assertNull($this->priceDate([]));
    }

    public function testAnEmptyPriceDateIsTheSameAsLeavingItOut(): void
    {
        self::assertNull($this->priceDate(['budget' => ['price_date' => null]]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refusedDates(): iterable
    {
        yield 'not a date' => ['last week'];
        yield 'a day that does not exist' => ['2026-02-30'];
        yield 'a day without zeros' => ['2026-1-3'];
        yield 'a number' => [20261113];
        yield 'a timestamp that is not a midnight' => [1794528001];
    }

    #[DataProvider('refusedDates')]
    public function testAPriceDateThatIsNotADayWrittenAsYearMonthDayIsRefused(mixed $date): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/price_date/');

        $this->priceDate(['budget' => ['price_date' => $date]]);
    }

    public function testTheShadowCeilingsAreNoLongerABudgetKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/Unrecognized option "shadow_ceilings" under "intakt\.budget"/');

        $this->ceilings(['budget' => ['shadow_ceilings' => ['five-hour' => 70]]]);
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

    /**
     * @param array<mixed> $contents
     */
    private function priceDate(array $contents): ?string
    {
        /** @var array{budget: array{price_date: string|null}} $configuration */
        $configuration = new Processor()->processConfiguration(new BudgetConfiguration(), [$contents]);

        return $configuration['budget']['price_date'];
    }
}
