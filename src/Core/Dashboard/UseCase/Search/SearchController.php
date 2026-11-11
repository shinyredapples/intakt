<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\Search;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\SearchQuery;
use Intakt\Core\Dashboard\Infrastructure\SearchIndex;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final readonly class SearchController
{
    public function __construct(
        private SearchIndex $index,
        private Environment $twig,
    ) {
    }

    #[Route('/search', name: DashboardRoutes::SEARCH, methods: ['GET'])]
    public function __invoke(#[MapQueryParameter] string $q = ''): Response
    {
        $answer = $this->index->answer(new SearchQuery($q));

        return new Response($this->twig->render('dashboard/_search_results.html.twig', ['answer' => $answer]));
    }
}
