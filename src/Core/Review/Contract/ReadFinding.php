<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class ReadFinding
{
    // In characters, so a finding written as one long line still reads as a title wherever it is shown.
    private const int TITLE_LENGTH = 160;

    public function __construct(
        public Severity $severity,
        public ?string $file,
        public ?int $line,
        public string $title,
    ) {
    }

    public static function of(Finding $finding): self
    {
        return new self($finding->severity, $finding->file, $finding->line, self::titleOf($finding->text));
    }

    // The first line of its text, because a finding has no title of its own and the rest of its text is the explanation.
    private static function titleOf(string $text): string
    {
        $title = trim(explode("\n", trim($text), 2)[0]);

        if (mb_strlen($title) <= self::TITLE_LENGTH) {
            return $title;
        }

        // Cut by characters, so a multibyte character is never split, and marked so a reader sees the title goes on.
        return rtrim(mb_substr($title, 0, self::TITLE_LENGTH - 1)).'…';
    }
}
