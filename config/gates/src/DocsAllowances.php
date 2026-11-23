<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class DocsAllowances
{
    /** @var array<string, array<string, int>> */
    private array $oversizedOnTarget;

    /** @var array<string, int> */
    private array $oversizedPagesOnTarget;

    /** @var array<string, int> */
    private array $unsummarizedOnTarget;

    /**
     * @param array<string, DocsPage> $targetPages
     */
    public function __construct(
        private DocsRatchetList $list,
        array $targetPages,
    ) {
        $this->oversizedOnTarget = $this->oversizedOnTarget($targetPages);
        $this->oversizedPagesOnTarget = array_map(static fn (DocsPage $page): int => $page->words, array_filter($targetPages, static fn (DocsPage $page): bool => $page->isOverPageLimit()));
        $this->unsummarizedOnTarget = array_map(static fn (DocsPage $page): int => $page->words, array_filter($targetPages, static fn (DocsPage $page): bool => null !== $page->summaryProblem));
    }

    // Because only what a change adds or alters must meet the limit, a wall may stay as often as the target branch or the list has it, but only on its own page.
    public function paragraphsOf(string $page, string $key): int
    {
        return max($this->list->paragraphCount($page, $key), $this->oversizedOnTarget[$page][$key] ?? 0);
    }

    public function pageCeilingOf(string $page): ?int
    {
        return $this->ceilingOf($this->list->pages[$page] ?? null, $this->oversizedPagesOnTarget[$page] ?? null);
    }

    // Like a page ceiling: a page that lacks its summary may stay so only while it does not grow.
    public function excusesSummary(DocsPage $page): bool
    {
        return $page->words <= ($this->ceilingOf($this->list->summaries[$page->path] ?? null, $this->unsummarizedOnTarget[$page->path] ?? null) ?? 0);
    }

    /**
     * @param array<string, DocsPage> $targetPages
     *
     * @return array<string, array<string, int>>
     */
    private function oversizedOnTarget(array $targetPages): array
    {
        return array_map(static fn (DocsPage $page): array => $page->oversizedCounts, $targetPages);
    }

    private function ceilingOf(?int $listed, ?int $onTarget): ?int
    {
        return null === $listed && null === $onTarget ? null : max($listed ?? 0, $onTarget ?? 0);
    }
}
