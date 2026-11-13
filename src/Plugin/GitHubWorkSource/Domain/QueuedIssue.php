<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use DateTimeImmutable;

// What the listing showed of one open issue or pull request, and what its history said once read, because only an item that can steer the queue is worth that read.
final class QueuedIssue
{
    private bool $trusted = false;

    /** @var list<string> */
    private array $vouchedLabels = [];

    public function __construct(
        private readonly ItemReading $reading,
    ) {
    }

    public function vouch(Vouching $vouching): void
    {
        $this->trusted = $vouching->trusted;
        $this->vouchedLabels = $vouching->labels;
    }

    public function number(): int
    {
        return $this->reading->listed->number;
    }

    public function isPullRequest(): bool
    {
        return $this->reading->listed->isPullRequest;
    }

    public function hasLabel(string $label): bool
    {
        return in_array($label, $this->reading->listed->labels, true);
    }

    // Only a label an account with write access applied last steers, because a triage account can set labels too.
    public function hasVouchedLabel(string $label): bool
    {
        return $this->hasLabel($label) && in_array($label, $this->vouchedLabels, true);
    }

    public function priority(): Priority
    {
        return Priority::of(array_values(array_filter($this->vouchedLabels, $this->hasLabel(...))));
    }

    public function isTrusted(): bool
    {
        return $this->trusted;
    }

    public function milestoneDue(): ?DateTimeImmutable
    {
        return $this->reading->listed->milestoneDue;
    }

    /**
     * @return list<int>
     */
    public function waitsFor(): array
    {
        return $this->reading->waitsFor;
    }

    /**
     * @return list<int>
     */
    public function closedBy(): array
    {
        return $this->reading->closedBy;
    }
}
