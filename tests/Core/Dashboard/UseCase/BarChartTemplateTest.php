<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Domain\ChartBar;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewsPerDay;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

#[CoversNothing]
final class BarChartTemplateTest extends KernelTestCase
{
    public function testADayCostingAHundredThousandthOfADollarNextToADollarDayDrawsABarOfPositiveHeight(): void
    {
        $heights = $this->barHeights($this->costChart())[0];

        self::assertCount(1, $heights);
        self::assertGreaterThan(0.0, $heights[0]);
    }

    public function testADayWithAnUnknownCostEstimateDrawsNoBarAndReadsNoData(): void
    {
        $chart = $this->costChart();

        self::assertSame([], $this->barHeights($chart)[2]);
        self::assertStringStartsWith('No data,', (string) $chart->filter('rect.c-hit')->eq(2)->attr('aria-label'));
    }

    public function testAPricedDayCostingExactlyZeroDrawsNoBar(): void
    {
        self::assertSame([], $this->barHeights($this->costChart())[3]);
    }

    public function testAPositivePartBelowTheDrawingResolutionIsDrawnAtLeastOneUnitTall(): void
    {
        $chart = $this->render([new ChartBar('Mon', 1000, '1000 rounds', 'Mon', 1), new ChartBar('Tue', 0, '0 rounds', 'Tue', 0)], 1000);

        [$tallDay, $zeroDay] = $this->barHeights($chart);

        self::assertCount(2, $tallDay);
        self::assertGreaterThanOrEqual(1.0, min($tallDay[0], $tallDay[1]));
        self::assertSame([], $zeroDay);
    }

    #[TestWith([365])]
    #[TestWith([1897])]
    #[TestWith([2500])]
    #[TestWith([3001])]
    #[TestWith([5000])]
    #[TestWith([7777])]
    public function testEveryBarStaysAtLeastATenthOfAUnitWideInItsWrittenCoordinatesWhenThousandsOfDaysAreDrawn(int $days): void
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $html = $twig->render('dashboard/components/bar_chart.html.twig', ['name' => 'Rounds', 'top' => 2, 'bars' => array_fill(0, $days, new ChartBar('Day', 1, '1 round', 'Day'))]);

        self::assertSame($days, preg_match_all('/<path d="M([\d.]+),[^"]* H[\d.]+ Q([\d.]+),[^"]*" class="c-bar"/', $html, $edges));
        $collapsed = [];
        foreach ($edges[1] as $bar => $leftEdge) {
            if (round((float) $edges[2][$bar] - (float) $leftEdge, 1) < 0.1) {
                $collapsed[] = $bar;
            }
        }

        self::assertSame([], $collapsed);
    }

    // At the 8 units a character the template reckons with, because a label that starts left of the drawing is cut by the page edge on a phone, where the chart sits at the page's padding.
    public function testEveryValueAxisLabelOfACostChartStartsInsideTheDrawing(): void
    {
        $starts = $this->costChart()->filter('text.c-axis[text-anchor="end"]')->each(static fn (Crawler $label): float => (float) $label->attr('x') - 8 * strlen($label->text()));

        self::assertCount(3, $starts);
        self::assertGreaterThanOrEqual(0.0, min($starts));
    }

    public function testACountChartKeepsItsNarrowGutterBecauseItsAxisLabelsAreShort(): void
    {
        $chart = $this->render([new ChartBar('Mon', 2, '2 rounds', 'Mon'), new ChartBar('Tue', 1, '1 round', 'Tue')], 2);

        self::assertSame(['36', '36', '36'], $chart->filter('line')->each(static fn (Crawler $line): ?string => $line->attr('x1')));
    }

    private function costChart(): Crawler
    {
        $rounds = [];
        $days = [
            [0.00001, new DateTimeImmutable('2026-10-01T10:00:00+00:00')],
            [1.0, new DateTimeImmutable('2026-10-02T10:00:00+00:00')],
            [null, new DateTimeImmutable('2026-10-03T10:00:00+00:00')],
            [0.0, new DateTimeImmutable('2026-10-04T10:00:00+00:00')],
        ];
        foreach ($days as $day => [$usd, $at]) {
            $rounds[] = new ReviewedRound(new RoundStart('r-'.$day, 'alpha', 2), new RoundPlace('feat/thing', 7), $at, null)
                ->withTokens(new TokenFigures(100, 0, 0, 0, 20))
                ->withCost(null === $usd ? Cost::unknown('model-x') : Cost::inUsd($usd, '2026-11-13'));
        }
        $perDay = ReviewsPerDay::of($rounds);
        self::assertNotNull($perDay);

        return $this->render($perDay->costBars(), $perDay->costTop(), $perDay->costDivisor());
    }

    /**
     * @param list<ChartBar> $bars
     */
    private function render(array $bars, int $top, int $divisor = 1): Crawler
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return new Crawler($twig->render('dashboard/components/bar_chart.html.twig', ['name' => 'Cost', 'top' => $top, 'bars' => $bars, 'divisor' => $divisor]));
    }

    /**
     * @return list<list<float>>
     */
    private function barHeights(Crawler $chart): array
    {
        $paths = $chart->filter('path.c-bar')->each(static fn (Crawler $path): string => (string) $path->attr('d'));

        return $chart->filter('rect.c-hit')->each(static function (Crawler $slot) use ($paths): array {
            $from = (float) $slot->attr('x');
            $to = $from + (float) $slot->attr('width');
            $heights = [];
            foreach ($paths as $path) {
                if (1 === preg_match('/^M([\d.]+),([\d.]+) V[\d.]+ Q[\d.]+,([\d.]+)/', $path, $coordinates) && (float) $coordinates[1] >= $from && (float) $coordinates[1] < $to) {
                    $heights[] = round((float) $coordinates[2] - (float) $coordinates[3], 1);
                }
            }

            return $heights;
        });
    }
}
