<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Review\Contract\NearLine;
use Intakt\Core\Review\Contract\QuoteNearLines;
use Intakt\Core\Review\Contract\QuotesCounted;

final readonly class RoundFinding
{
    public JudgeCall $call;

    /**
     * @param list<string>         $merged
     * @param list<QuoteNearLines> $nearest
     */
    public function __construct(
        public string $id,
        public ?string $severity,
        public ?string $text,
        public array $merged = [],
        public ?string $file = null,
        public ?int $line = null,
        public ?string $droppedBecause = null,
        public QuotesCounted $quotes = new QuotesCounted(),
        private array $nearest = [],
    ) {
        $this->call = JudgeCall::NotActedOn;
    }

    /**
     * @return list<NearLine>
     */
    public function nearLinesOf(string $quote): array
    {
        // Looked up by the quote the ledger row recorded, because the page never gets the change text and must not search it again.
        return array_find($this->nearest, static fn (QuoteNearLines $near): bool => $near->quote === $quote)->lines ?? [];
    }

    /**
     * @return non-empty-list<string>
     */
    public function ids(): array
    {
        return [$this->id, ...$this->merged];
    }

    public function urgency(): ?FindingUrgency
    {
        return FindingUrgency::of($this->severity);
    }

    public function place(): ?string
    {
        if (null === $this->file) {
            return null;
        }

        return null === $this->line ? $this->file : $this->file.':'.$this->line;
    }

    public function callInWords(): string
    {
        return $this->call->inWords();
    }

    public function calledBy(JudgeCall $call): self
    {
        return clone ($this, ['call' => $call]);
    }
}
