<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\ReviewerForLoop\Contract\ProjectMode;

// The latest event of a reviewer decides its row, because a reviewer started again after a stopped worker runs anew.
final readonly class ReviewerOutcome
{
    public ReviewerProgress $progress;
    public ?Decision $decision;
    public ?int $findings;
    public ?string $note;
    public ?string $substitute;
    public ProjectMode $mode;

    public function __construct(
        public string $id,
        public string $role,
        public string $tier,
        public DateTimeImmutable $since,
        public ?string $model = null,
    ) {
        $this->progress = ReviewerProgress::Running;
        $this->decision = null;
        $this->findings = null;
        $this->note = null;
        $this->substitute = null;
        $this->mode = ProjectMode::Live;
    }

    // The model comes from the latest start alone, because a reviewer started again may run on another model, and a start written before the model was recorded has none.
    public function restarted(DateTimeImmutable $at, ?string $model): self
    {
        return new self($this->id, $this->role, $this->tier, $at, $model);
    }

    // The tier stands in for a start written before Intakt recorded the model, so every place that names a reviewer says the same.
    public function modelInWords(): string
    {
        return $this->model ?? $this->tier.' tier, model not recorded';
    }

    public function outcome(): ?ReviewOutcome
    {
        return ReviewOutcome::ofReviewer($this);
    }

    public function answered(Decision $decision, int $findings, ProjectMode $mode): self
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

    public function substitutedBy(string $provider): self
    {
        return clone ($this, ['substitute' => $provider]);
    }
}
