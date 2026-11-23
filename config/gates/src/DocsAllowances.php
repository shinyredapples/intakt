<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because the target branch moves on while a change waits, a wall it already has that its own list does not count is not this change's doing.
final readonly class DocsAllowances
{
    public function __construct(
        private DocsRatchetList $list,
        private DocsRatchetList $targetList,
        private ?MarkdownPage $target,
    ) {
    }

    public static function of(DocsRatchetList $list): self
    {
        return new self($list, DocsRatchetList::empty(), null);
    }

    public function paragraphsOf(string $page, string $key): int
    {
        return $this->list->allowanceOf($page, $key) + max(0, $this->oversizedOnTarget($key) - $this->targetList->allowanceOf($page, $key));
    }

    public function pageCeilingOf(string $page): int
    {
        $ceiling = $this->list->ceilingOf($page) ?? DocsGate::PAGE_WORDS;

        if ($this->target instanceof MarkdownPage && null === $this->targetList->ceilingOf($page)) {
            return max($ceiling, $this->target->words());
        }

        return $ceiling;
    }

    public function excusesSummary(string $page): bool
    {
        if ($this->list->excusesSummary($page)) {
            return true;
        }

        return true === $this->target?->breaksTheSummaryRule() && !$this->targetList->excusesSummary($page);
    }

    private function oversizedOnTarget(string $key): int
    {
        $paragraphs = $this->target?->paragraphs() ?? [];

        return count(array_filter($paragraphs, static fn (MarkdownParagraph $paragraph): bool => $paragraph->words() > DocsGate::PARAGRAPH_WORDS && $paragraph->key() === $key));
    }
}
