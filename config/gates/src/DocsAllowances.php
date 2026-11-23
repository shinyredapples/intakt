<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because the target branch moves on while a change waits, a wall it already has that its own list does not count is not this change's doing.
final readonly class DocsAllowances
{
    /**
     * @var array<string, int>
     */
    private array $oversizedOnTarget;

    public function __construct(
        private DocsRatchetList $list,
        private DocsRatchetList $targetList,
        private ?MarkdownPage $target,
    ) {
        $this->oversizedOnTarget = $this->oversizedKeysOf($target);
    }

    public static function of(DocsRatchetList $list): self
    {
        return new self($list, DocsRatchetList::empty(), null);
    }

    public function paragraphsOf(string $page, string $key): int
    {
        return $this->list->allowanceOf($page, $key) + max(0, ($this->oversizedOnTarget[$key] ?? 0) - $this->targetList->allowanceOf($page, $key));
    }

    public function pageCeilingOf(string $page): int
    {
        $ceiling = $this->list->ceilingOf($page) ?? DocsGate::PAGE_WORDS;

        if ($this->target instanceof MarkdownPage && null === $this->targetList->ceilingOf($page)) {
            return max($ceiling, $this->target->words());
        }

        return $ceiling;
    }

    public function excusesSummary(string $page, int $words): bool
    {
        if ($this->list->excusesSummary($page)) {
            return true;
        }

        return $this->target instanceof MarkdownPage
            && $this->target->breaksTheSummaryRule()
            && !$this->targetList->excusesSummary($page)
            && $words <= $this->target->words();
    }

    /**
     * @return array<string, int>
     */
    private function oversizedKeysOf(?MarkdownPage $page): array
    {
        $counts = [];

        foreach ($page?->paragraphs() ?? [] as $paragraph) {
            if ($paragraph->words() > DocsGate::PARAGRAPH_WORDS) {
                $counts[$paragraph->key()] = ($counts[$paragraph->key()] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
