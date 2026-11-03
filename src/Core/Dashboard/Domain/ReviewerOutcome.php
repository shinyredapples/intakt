<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Shadow\Contract\ProjectMode;

// The latest event of a reviewer decides its row, because a reviewer started again after a stopped worker runs anew.
final readonly class ReviewerOutcome
{
    public ReviewerProgress $progress;
    public ?string $decision;
    public ?int $findings;
    public ?string $note;
    public ?string $substitute;
    public ProjectMode $mode;

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
        $this->mode = ProjectMode::Live;
    }

    public function restarted(): self
    {
        return new self($this->id, $this->role, $this->tier);
    }

    public function answered(string $decision, int $findings, ProjectMode $mode): self
    {
        return clone ($this, ['progress' => ReviewerProgress::Answered, 'decision' => $decision, 'findings' => $findings, 'mode' => $mode]);
    }

    public function failed(string $failure): self
    {
        return clone ($this, ['progress' => ReviewerProgress::Failed, 'note' => $failure]);
    }

    public function blocked(string $reason): self
    {
        return clone ($this, ['progress' => ReviewerProgress::Blocked, 'note' => $reason]);
    }

    public function stopped(): self
    {
        return clone ($this, ['progress' => ReviewerProgress::Stopped]);
    }

    // What follows a stop (the blocked verdict the stopped reviewer returns) is the stop's own consequence, so the row keeps saying stopped.
    public function isStopped(): bool
    {
        return ReviewerProgress::Stopped === $this->progress;
    }

    public function substitutedBy(string $provider): self
    {
        return clone ($this, ['substitute' => $provider]);
    }
}
