<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

// A reviewer names its own finding ids, so an id may hold a slash or be "..": a browser resolves such segments before it asks (checks/../F1 would open F1). The id is percent-encoded once more than the router does, dots included, so it reaches the server as one segment that is never a dot segment.
final readonly class FindingPaths
{
    public function __construct(
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[AsTwigFunction('finding_path')]
    public function of(string $round, string $finding): string
    {
        return $this->urls->generate(DashboardRoutes::SHOW_FINDING, [
            'round' => $round,
            'finding' => str_replace('.', '%2E', rawurlencode($finding)),
        ]);
    }

    // Decoded once more, because the router decodes the path only once and of() encoded the id twice.
    public function findingIn(string $segment): string
    {
        return rawurldecode($segment);
    }
}
