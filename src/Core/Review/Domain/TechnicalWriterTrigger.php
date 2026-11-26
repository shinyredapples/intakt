<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\DocsPages;
use Intakt\Core\Review\Contract\PathGlob;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Plain code decides whether the Technical writer joins, from the project's paths, the summaries and the label, never the model, because a steered model must not choose who reviews it (docs/design/05-architecture/07-reviews.md#the-technical-writer).
#[Exclude]
final readonly class TechnicalWriterTrigger
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        private array $paths,
        private DocsPages $docs = new DocsPages(),
    ) {
    }

    public function matches(ChangeDescription $change): bool
    {
        $pages = array_values(array_filter([...$change->paths], $this->docs->contains(...)));

        // The label and the summaries only widen which pages count, so a change that touches no page people read never gets the role.
        if ([] === $pages) {
            return false;
        }

        return $change->asksForTechnicalWriter()
            || [] !== array_intersect($pages, [...$change->summaries])
            || array_any([...$change->paths], $this->isTriggerPath(...));
    }

    private function isTriggerPath(string $path): bool
    {
        return array_any($this->paths, static fn (string $glob): bool => new PathGlob($glob)->matches($path));
    }
}
