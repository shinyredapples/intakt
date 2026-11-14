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
    ) {
        $this->call = JudgeCall::NotActedOn;
    }

    // Joined with "+" as intakt:review prints an act entry, so the page and the console name a merged entry alike.
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

    public function calledBy(JudgeCall $call): self
    {
        return clone ($this, ['call' => $call]);
    }
}
