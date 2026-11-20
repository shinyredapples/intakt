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

        self::assertCount(2, $this->barHeights($chart)[0]);
        self::assertGreaterThanOrEqual(1.0, min($this->barHeights($chart)[0]));
        self::assertSame([], $this->barHeights($chart)[1]);
    }

    public function testBarsStayAtLeastATenthOfAUnitWideWhenThousandsOfDaysAreDrawn(): void
    {
        $bars = array_map(static fn (int $day): ChartBar => new ChartBar('d'.$day, 1, '1 round', 'd'.$day), range(1, 5000));
        $path = (string) $this->render($bars, 1)->filter('path.c-bar')->first()->attr('d');

        self::assertSame(1, preg_match('/^M([\d.]+),.* H[\d.]+ Q([\d.]+),/', $path, $edges));
        self::assertGreaterThanOrEqual(0.1, round((float) $edges[2] - (float) $edges[1], 1));
    }

    private function costChart(): Crawler
    {
        $rounds = [];
        foreach ([0.00001, 1.0, null, 0.0] as $day => $usd) {
            $rounds[] = new ReviewedRound(new RoundStart('r-'.$day, 'alpha', 2), new RoundPlace('feat/thing', 7), new DateTimeImmutable(sprintf('2026-10-%02dT10:00:00+00:00', $day + 1)), null)
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
                preg_match('/^M([\d.]+),([\d.]+) V[\d.]+ Q[\d.]+,([\d.]+)/', $path, $coordinates);
                if ((float) $coordinates[1] >= $from && (float) $coordinates[1] < $to) {
                    $heights[] = round((float) $coordinates[2] - (float) $coordinates[3], 1);
                }
            }

            return $heights;
        });
    }
}
