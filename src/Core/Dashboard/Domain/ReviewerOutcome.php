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
    // False until a start is recorded, because a reviewer whose message failed for good is blocked without ever starting.
    public bool $started;

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
        $this->started = false;
    }

    // The model comes from the latest start alone, because a reviewer started again may run on another model, and a start written before the model was recorded has none.
    public function restarted(DateTimeImmutable $at, ?string $model): self
    {
        return clone (new self($this->id, $this->role, $this->tier, $at, $model), ['started' => true]);
    }

    // The tier stands in for a start written before Intakt recorded the model, so every place that names a reviewer says the same.
    public function modelInWords(): string
    {
        return null === $this->model || '' === $this->model ? $this->tier.' tier, model not recorded' : $this->model;
    }

    // Apart from modelInWords(), because the review page names the model and its AI lab for a reader new to the ids while the CLI, MCP and docs keep the raw id (#3183).
    public function modelAndLab(): string
    {
        if (null === $this->model || '' === $this->model) {
            return $this->modelInWords();
        }

        $lab = AiLab::of($this->model);
        $name = AiLab::modelName($this->model);

        // The id alone also for a bare lab namespace ("anthropic/"), because it names no model to put before the lab.
        return $lab instanceof AiLab && '' !== $name ? $name.' ('.$lab->value.')' : $this->model;
    }

    public function outcome(): ?ReviewOutcome
    {
        return ReviewOutcome::ofReviewer($this);
    }

    public function answered(Decision $decision, int $findings, ProjectMode $mode): self
    {
        return clone ($this, ['progress' => ReviewerProgress::Answered, 'decision' => $decision, 'findings' => $findings, 'mode' => $mode]);
    }

    // An answer's own reason, so a blocked verdict Intakt gave is named like a reviewer blocked before it answered.
    public function because(?string $reason): self
    {
        return clone ($this, ['note' => $reason]);
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

    // The substitute's model replaces the start's, so the model and the substitute always name the same provider; a catch-up recorded without one keeps the start's model.
    public function substitutedBy(string $provider, ?string $model): self
    {
        return clone ($this, ['substitute' => $provider, 'model' => $model ?? $this->model]);
    }
}
