<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowComponents;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\BarChart;
use Intakt\Core\Dashboard\Domain\ChartPoint;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final readonly class ShowComponentsController
{
    public function __construct(
        private Environment $twig,
    ) {
    }

    #[Route('/components', name: DashboardRoutes::SHOW_COMPONENTS, methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response($this->twig->render('dashboard/components.html.twig', ['charts' => $this->exampleCharts()]));
    }

    // Fixed example figures, so the chart shows a missing day and a zero without the Trends data.
    /**
     * @return array<string, BarChart>
     */
    private function exampleCharts(): array
    {
        $roundsPerDay = [3, 5, 2, 4, null, 1, 6, 4, 3, 5, 2, 4, 3, 6];
        $findingsPerRound = [7, 4, 2, 0, 1, 0];

        return [
            'rounds' => new BarChart(
                'Review rounds per day',
                '48 rounds in 14 days, at most 6 a day; Nov 5 has no record.',
                'Day',
                'Rounds',
                array_map(static fn (int $day, ?int $rounds): ChartPoint => new ChartPoint('Nov '.($day + 1), $rounds), array_keys($roundsPerDay), $roundsPerDay),
            ),
            'findings' => new BarChart(
                'Findings per round',
                'Findings fell from 7 in round 1 to none in round 6.',
                'Round',
                'Findings',
                array_map(static fn (int $round, int $findings): ChartPoint => new ChartPoint('Round '.($round + 1), $findings), array_keys($findingsPerRound), $findingsPerRound),
            ),
        ];
    }
}
