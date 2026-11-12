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
    private ?string $milestoneDue;

    #[ORM\Column(nullable: true)]
    private ?string $editor;

    #[ORM\Column]
    private bool $trusted;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $waitsFor;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $closedBy;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $failedReason = null;

    #[ORM\Column]
    private bool $pullRequest;

    /**
     * @param list<int> $waitsFor
     * @param list<int> $closedBy
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private readonly string $project,
        #[ORM\Id]
        #[ORM\Column]
        private readonly int $number,
        ListedIssue $listed,
        ?string $editor,
        bool $trusted,
        array $waitsFor,
        array $closedBy,
    ) {
        $this->pullRequest = $listed->isPullRequest;
        $this->see($listed, $editor, $trusted, $waitsFor, $closedBy);
    }

    /**
     * @param list<int> $waitsFor
     * @param list<int> $closedBy
     */
    public function see(ListedIssue $listed, ?string $editor, bool $trusted, array $waitsFor, array $closedBy): void
    {
        $this->title = mb_substr($listed->title, 0, 255);
        $this->labels = $listed->labels;
        $this->author = $listed->author;
        $this->updatedAt = $this->moment($listed->updatedAt);
        $this->milestoneDue = $listed->milestoneDue?->setTimezone(new DateTimeZone('UTC'))->format(self::DUE);
        $this->editor = $editor;
        $this->trusted = $trusted;
        $this->waitsFor = $waitsFor;
        $this->closedBy = $closedBy;
    }

    public function isStillAt(ListedIssue $listed): bool
    {
        return $this->updatedAt === $this->moment($listed->updatedAt) && $this->pullRequest === $listed->isPullRequest;
    }

    public function failForGood(string $reason): void
    {
        $this->failedReason = $reason;
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

    public function hasLabel(string $label): bool
    {
        return in_array($label, $this->labels, true);
    }

    public function priority(): Priority
    {
        return Priority::of($this->labels);
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

    public function failedReason(): ?string
    {
        return $this->failedReason;
    }

    private function moment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format(self::MOMENT);
    }
}
