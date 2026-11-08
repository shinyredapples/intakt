<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\GlossaryTerm;
use Twig\Attribute\AsTwigFunction;

final readonly class GlossaryExplanations
{
    // An unknown term fails the page rather than showing a term without its explanation.
    #[AsTwigFunction('glossary_explanation')]
    public function of(string $term): string
    {
        return GlossaryTerm::from($term)->explanation();
    }
}
