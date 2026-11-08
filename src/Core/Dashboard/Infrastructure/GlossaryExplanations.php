<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\GlossaryTerm;
use Twig\Attribute\AsTwigFunction;

final readonly class GlossaryExplanations
{
    // Throws on an unknown term, because a term shown without its standard explanation breaks the glossary rule.
    #[AsTwigFunction('glossary_explanation')]
    public function of(string $term): string
    {
        return GlossaryTerm::from($term)->explanation();
    }
}
