<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Recorded with the round, because plain code dropped it before the judge, and the reason is all that says why it is on no list.
final readonly class DroppedFinding
{
    /**
     * @param list<QuoteNearLines> $nearest
     */
    public function __construct(
        public string $id,
        public Severity $severity,
        public string $text,
        public ?string $file,
        public ?int $line,
        public string $reason,
        // Empty by default, because a row written before nearest lines were recorded holds none.
        public array $nearest = [],
    ) {
    }

    /**
     * @param list<QuoteNearLines> $nearest
     */
    public static function of(Finding $finding, string $reason, array $nearest = []): self
    {
        return new self($finding->id, $finding->severity, $finding->text, $finding->file, $finding->line, $reason, $nearest);
    }
}
