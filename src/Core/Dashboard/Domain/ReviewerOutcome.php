<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// The latest event of a reviewer decides its row, because a reviewer started again after a stopped worker runs anew.
final readonly class ReviewerOutcome
{
    public ReviewerProgress $progress;
    public ?string $decision;
    public ?int $findings;
    public ?string $note;
    public ?string $substitute;

    public function __construct(
        public string $id,
        public string $role,
        public string $tier,
    ) {
        $this->progress = ReviewerProgress::Running;
        $this->decision = null;
        $this->findings = null;
        $this->note = null;
        $this->substitute = null;
    }

    public function restarted(): self
    {
        return new self($this->id, $this->role, $this->tier);
    }

    public function answered(string $decision, int $findings): self
    {
        return clone ($this, ['progress' => ReviewerProgress::Answered, 'decision' => $decision, 'findings' => $findings]);
    }

    public function failed(string $failure): self
    {
        return clone ($this, ['progress' => ReviewerProgress::Failed, 'note' => $failure]);
    }

    public function blocked(string $reason): self
    {
        return clone ($this, ['progress' => ReviewerProgress::Blocked, 'note' => $reason]);
    }

    public function substitutedBy(string $provider): self
    {
        return clone ($this, ['substitute' => $provider]);
    }
}
