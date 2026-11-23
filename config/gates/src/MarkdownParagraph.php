<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class MarkdownParagraph
{
    public function __construct(
        public string $text,
        public int $words,
    ) {
    }

    // 16 hex characters keep a ratchet list short and readable, and a collision among one repository's paragraphs stays unlikely.
    public function key(): string
    {
        return hash('sha256', $this->text) |> (static fn (string $hash): string => substr($hash, 0, 16));
    }
}
