<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ReadFinding
{
    // In characters, so a finding written as one long line still reads as a title wherever it is shown.
    private const int TITLE_LENGTH = 160;

    public function __construct(
        public Severity $severity,
        public ?string $file,
        public ?int $line,
        public string $title,
        // A title can end in an ellipsis the reviewer wrote, so whoever shows it learns here whether it was cut.
        public bool $titleCut,
    ) {
    }

    public static function of(Finding $finding): self
    {
        // The first line of its text, because a finding has no title of its own and the rest of its text is the explanation.
        $title = trim(explode("\n", trim($finding->text), 2)[0]);
        $cut = mb_strlen($title) > self::TITLE_LENGTH;

        return new self($finding->severity, $finding->file, $finding->line, $cut ? self::shortened($title) : $title, $cut);
    }

    // Cut by characters, so a multibyte character is never split, and marked so a reader sees the title goes on.
    private static function shortened(string $title): string
    {
        return rtrim(mb_substr($title, 0, self::TITLE_LENGTH - 1)).'…';
    }
}
