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
        public ?string $droppedBecause = null,
    ) {
        $this->call = JudgeCall::NotActedOn;
    }

    /**
     * @return non-empty-list<string>
     */
    public function ids(): array
    {
        return [$this->id, ...$this->merged];
    }

    public function place(): ?string
    {
        if (null === $this->file) {
            return null;
        }

        return null === $this->line ? $this->file : $this->file.':'.$this->line;
    }

    // The first sentence of the text, so a finding fits one row of the verdict card; the full text stays on its card.
    public function headline(): ?string
    {
        if (null === $this->text) {
            return null;
        }

        return 1 === preg_match('/^.+?[.!?](?=\s|$)/s', $this->text, $match) ? $match[0] : $this->text;
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
