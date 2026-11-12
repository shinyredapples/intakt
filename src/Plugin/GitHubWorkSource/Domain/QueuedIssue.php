<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;

// What the last complete listing showed of one open issue or pull request, so an unchanged item is not read again and a wait is answered from the listing.
#[ORM\Entity]
#[ORM\Table(name: 'work_source_issue')]
final class QueuedIssue
{
    // Text rather than a datetime column, because the platforms' datetime columns keep whole seconds and an edit within the same second would then go unseen.
    private const string MOMENT = 'Y-m-d H:i:s.u';
    private const string DUE = 'Y-m-d\TH:i:s';

    #[ORM\Column(length: 255)]
    private string $title;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $labels;

    #[ORM\Column(length: 255)]
    private string $author;

    #[ORM\Column(length: 26)]
    private string $updatedAt;

    #[ORM\Column(length: 19, nullable: true)]
    private ?string $milestoneDue = null;

    // Null until the item's history was read, which happens only for an item that can steer the queue.
    #[ORM\Column(nullable: true)]
    private ?string $editor;

    #[ORM\Column]
    private bool $trusted;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $vouchedLabels;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $waitsFor;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $closedBy;

    #[ORM\Column]
    private bool $pullRequest;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private readonly string $project,
        #[ORM\Id]
        #[ORM\Column]
        private readonly int $number,
        ItemReading $reading,
    ) {
        $this->pullRequest = $reading->listed->isPullRequest;
        $this->see($reading);
    }

    // A changed item forgets what its history said, because the edit may be by someone else.
    public function see(ItemReading $reading): void
    {
        $listed = $reading->listed;
        $this->title = mb_substr($listed->title, 0, 255);
        $this->labels = $listed->labels;
        $this->author = $listed->author;
        $this->updatedAt = $this->moment($listed->updatedAt);
        $this->waitsFor = $reading->waitsFor;
        $this->closedBy = $reading->closedBy;
        $this->editor = null;
        $this->trusted = false;
        $this->vouchedLabels = [];
        $this->dueOn($listed->milestoneDue);
    }

    // Taken from every full listing, because a milestone's due date moves without moving the update time of its issues.
    public function dueOn(?DateTimeImmutable $due): void
    {
        $this->milestoneDue = $due?->setTimezone(new DateTimeZone('UTC'))->format(self::DUE);
    }

    public function vouch(Vouching $vouching): void
    {
        $this->editor = $vouching->editor;
        $this->trusted = $vouching->trusted;
        $this->vouchedLabels = $vouching->labels;
    }

    public function isStillAt(ListedIssue $listed): bool
    {
        return $this->updatedAt === $this->moment($listed->updatedAt) && $this->pullRequest === $listed->isPullRequest;
    }

    public function hasHistory(): bool
    {
        return null !== $this->editor;
    }

    public function project(): string
    {
        return $this->project;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function isPullRequest(): bool
    {
        return $this->pullRequest;
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        return $this->labels;
    }

    public function hasLabel(string $label): bool
    {
        return in_array($label, $this->labels, true);
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

    public function author(): string
    {
        return $this->author;
    }

    public function editor(): ?string
    {
        return $this->editor;
    }

    public function isTrusted(): bool
    {
        return $this->trusted;
    }

    // Moments in one fixed format, so the text compares as the moments do; no due date sorts after every one.
    public function milestoneDue(): ?string
    {
        return $this->milestoneDue;
    }

    /**
     * @return list<int>
     */
    public function waitsFor(): array
    {
        return $this->waitsFor;
    }

    /**
     * @return list<int>
     */
    public function closedBy(): array
    {
        return $this->closedBy;
    }

    private function moment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format(self::MOMENT);
    }
}
