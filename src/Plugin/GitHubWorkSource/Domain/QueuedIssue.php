<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use DateTimeImmutable;

// What the listing showed of one open issue or pull request, and what its history said once read, because only an item that can steer the queue is worth that read.
final readonly class QueuedIssue
{
    public function __construct(
        private ItemReading $reading,
        private ?Vouching $vouching = null,
    ) {
    }

    public function vouchedBy(Vouching $vouching): self
    {
        return new self($this->reading, $vouching);
    }

    public function number(): int
    {
        return $this->reading->listed->number;
    }

    public function title(): string
    {
        return $this->reading->listed->title;
    }

    public function author(): string
    {
        return $this->reading->listed->author;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->reading->listed->updatedAt;
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
        return $this->hasLabel($label) && in_array($label, $this->vouchedLabels(), true);
    }

    public function priority(): Priority
    {
        return Priority::of(array_values(array_filter($this->vouchedLabels(), $this->hasLabel(...))));
    }

    // An item whose history was not read is not trusted, because nothing then says who wrote its text and labels.
    public function isTrusted(): bool
    {
        return $this->vouching->trusted ?? false;
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
     * @return list<string>
     */
    public function unreadableWaits(): array
    {
        return $this->reading->unreadableWaits;
    }

    /**
     * @return list<int>
     */
    public function closedBy(): array
    {
        return $this->reading->closedBy;
    }

    /**
     * @return list<string>
     */
    private function vouchedLabels(): array
    {
        return $this->vouching->labels ?? [];
    }
}
