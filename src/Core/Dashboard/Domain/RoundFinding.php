<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class RoundFinding
{
    public JudgeCall $call;

    /**
     * @param list<string> $merged
     */
    public function __construct(
        public string $id,
        public ?string $severity,
        public ?string $text,
        public array $merged = [],
        public ?string $file = null,
        public ?int $line = null,
    ) {
        $this->call = JudgeCall::NotActedOn;
    }

    // Joined with "+" as ReviewReport::asLines() joins an act entry's ids for intakt:review, so the page and the console name a merged entry alike.
    public function entryId(): string
    {
        return implode('+', $this->ids());
    }

    /**
     * @return non-empty-list<string>
     */
    public function ids(): array
    {
        return [$this->id, ...$this->merged];
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
