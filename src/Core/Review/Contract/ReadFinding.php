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

    // The first line of its text, because a finding has no title of its own and the rest of its text is the explanation.
    public static function of(Finding $finding): self
    {
        return new self($finding->severity, $finding->file, $finding->line, trim(explode("\n", trim($finding->text), 2)[0]));
    }
}
