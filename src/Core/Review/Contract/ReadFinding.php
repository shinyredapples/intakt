<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class ReadFinding
{
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

    // The first line of its text, because a finding has no title of its own and the rest of its text is the explanation; uncut, so whoever shows it cuts what the reader sees, after reading its marks.
    private static function titleOf(string $text): string
    {
        // Split on the line endings by name rather than \R, which without UTF-8 mode would also split a multibyte character holding byte 0x85.
        $lines = preg_split('/\r\n|\r|\n/', trim($text), 2);

        return trim(false === $lines ? $text : $lines[0]);
    }
}
