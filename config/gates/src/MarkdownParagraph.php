<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class MarkdownParagraph
{
    public function __construct(
        public int $line,
        public string $text,
    ) {
    }

    public function words(): int
    {
        return MarkdownPage::wordsIn($this->text);
    }

    // Because the key must survive a reflow of the same words but change with any edit of them.
    public function key(): string
    {
        return hash('sha256', implode(' ', MarkdownPage::wordsOf($this->text)));
    }
}
