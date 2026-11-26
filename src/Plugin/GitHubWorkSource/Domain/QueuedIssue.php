<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use DateTimeImmutable;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;

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
        return $this->reading->listed->labels->contains($label);
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

    // The numbers a text names, not the text, because only they steer: a change to the wording alone leaves the item as planned.
    public function isListedAs(IssueHistory $history, IssueReferences $references): bool
    {
        $body = $history->body;

        return $this->sorted([...$this->reading->listed->labels]) === $this->sorted([...$history->currentLabels])
            && $this->sorted($this->reading->waitsFor) === $this->sorted($references->waitsFor($body))
            && $this->sorted($this->reading->unreadableWaits) === $this->sorted($references->unreadableWaits($body))
            && $this->sorted($this->reading->closedBy) === $this->sorted($references->closedBy($body));
    }

    // Only the labels the item carries, because those are what the eligibility and priority rules read; a label applied again by an account without write access leaves the label set as it was and is seen only here. Priority labels in the order they were applied, because the one applied last counts, and every other label as a set, because its order steers nothing.
    public function isVouchedAs(Vouching $other): bool
    {
        $own = array_values(array_filter($this->vouchedLabels(), $this->hasLabel(...)));
        $fresh = array_values(array_filter($other->labels, $this->hasLabel(...)));

        return $this->isTrusted() === $other->trusted
            && Priority::labelsIn($own) === Priority::labelsIn($fresh)
            && $this->sorted($own) === $this->sorted($fresh);
    }

    // Sorted as strings, because GitHub promises no order and a numeric sort holds `1` and `01` equal and leaves them in the order they came.
    /**
     * @param list<int|string> $labels
     *
     * @return list<int|string>
     */
    private function sorted(array $labels): array
    {
        sort($labels, SORT_STRING);

        return $labels;
    }

    /**
     * @return list<string>
     */
    private function vouchedLabels(): array
    {
        return $this->vouching->labels ?? [];
    }
}
