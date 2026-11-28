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
        public ?FindingKind $kind = null,
    ) {
    }

    public static function of(Finding $finding): self
    {
        return new self($finding->severity, $finding->file, $finding->line, self::titleOf($finding), $finding->kind);
    }

    // The reviewer's title, or the first line of its text for a finding written without one, because the rest of the text is the explanation; uncut, so whoever shows it cuts what the reader sees, after reading its marks.
    private static function titleOf(Finding $finding): string
    {
        $title = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', (string) $finding->title);
        if (null !== $title && '' !== $title) {
            return $title;
        }

        // Split on the line endings by name rather than \R, which without UTF-8 mode would also split a multibyte character holding byte 0x85.
        $lines = preg_split('/\r\n|\r|\n/', trim($finding->text), 2);

        return trim(false === $lines ? $finding->text : $lines[0]);
    }
}
