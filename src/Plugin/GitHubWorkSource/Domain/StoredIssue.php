<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use LogicException;

// What the last complete listing showed of one open issue or pull request, kept so an unchanged item is not read again and a wait is answered from the rows.
#[ORM\Entity]
#[ORM\Table(name: 'work_source_issue')]
final class StoredIssue
{
    // Text rather than a datetime column, because the platforms' datetime columns keep whole seconds and an edit within the same second would then go unseen.
    private const string MOMENT = 'Y-m-d H:i:s.u';

    #[ORM\Column(length: 255)]
    private string $title;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $labels;

    #[ORM\Column(length: 255)]
    private string $author;

    #[ORM\Column(length: 26)]
    private string $updatedAt;

    #[ORM\Column(length: 26, nullable: true)]
    private ?string $milestoneDue;

    #[ORM\Column]
    private bool $pullRequest;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $waitsFor;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $unreadableWaits;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $closedBy;

    // Null until the item's history was read, because only an item that can steer the queue is worth that read.
    #[ORM\Column(nullable: true)]
    private ?bool $trusted = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $vouchedLabels = [];

    // Both keys promoted, project first, because Doctrine orders the primary key as the properties are declared and the migration keys project, then number.
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private readonly string $project,
        #[ORM\Id]
        #[ORM\Column]
        private readonly int $number,
        ItemReading $reading,
    ) {
        $listed = $reading->listed;
        $this->title = mb_substr($listed->title, 0, 255);
        $this->labels = $listed->labels;
        $this->author = $listed->author;
        $this->updatedAt = $this->moment($listed->updatedAt);
        $this->milestoneDue = $listed->milestoneDue instanceof DateTimeImmutable ? $this->moment($listed->milestoneDue) : null;
        $this->pullRequest = $listed->isPullRequest;
        $this->waitsFor = $reading->waitsFor;
        $this->unreadableWaits = $reading->unreadableWaits;
        $this->closedBy = $reading->closedBy;
    }

    public static function of(string $project, ItemReading $reading): self
    {
        return new self($project, $reading->listed->number, $reading);
    }

    public function vouch(Vouching $vouching): void
    {
        $this->trusted = $vouching->trusted;
        $this->vouchedLabels = $vouching->labels;
    }

    // Kept only while the update time and what steers both stand, because an edit in the second of the earlier listing keeps the update time and the history vouched for the earlier labels and references.
    public function keepVouchingOf(self $stored): void
    {
        if ($this->number !== $stored->number || $this->updatedAt !== $stored->updatedAt || !$this->hasLabels($stored->labels) || !$this->namesNumbers($stored->waitsFor, $stored->unreadableWaits, $stored->closedBy)) {
            return;
        }

        $this->trusted = $stored->trusted;
        $this->vouchedLabels = $stored->vouchedLabels;
    }

    // The numbers a text names, not the text, because only they steer and a row whose listing went unchanged keeps them without the body.
    public function isListedAs(IssueHistory $history, IssueReferences $references): bool
    {
        $body = $history->body;

        return $this->hasLabels($history->currentLabels) && $this->namesNumbers($references->waitsFor($body), $references->unreadableWaits($body), $references->closedBy($body));
    }

    public function isVouched(): bool
    {
        return null !== $this->trusted;
    }

    // Copied field by field, because a newer reading of a stored item must update its row, not add a second one.
    public function replaceWith(self $newer): void
    {
        $this->title = $newer->title;
        $this->labels = $newer->labels;
        $this->author = $newer->author;
        $this->updatedAt = $newer->updatedAt;
        $this->milestoneDue = $newer->milestoneDue;
        $this->pullRequest = $newer->pullRequest;
        $this->waitsFor = $newer->waitsFor;
        $this->unreadableWaits = $newer->unreadableWaits;
        $this->closedBy = $newer->closedBy;
        $this->trusted = $newer->trusted;
        $this->vouchedLabels = $newer->vouchedLabels;
    }

    // The body comes back empty, because only the numbers it names steer the queue, and those are kept.
    public function queued(): QueuedIssue
    {
        $listed = new ListedIssue($this->number, $this->title, '', $this->labels, $this->author, $this->instant($this->updatedAt), null === $this->milestoneDue ? null : $this->instant($this->milestoneDue), $this->pullRequest);
        $reading = new ItemReading($listed, $this->waitsFor, $this->unreadableWaits, $this->closedBy);

        return new QueuedIssue($reading, $this->storedVouching());
    }

    public function project(): string
    {
        return $this->project;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function author(): string
    {
        return $this->author;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->instant($this->updatedAt);
    }

    public function isPullRequest(): bool
    {
        return $this->pullRequest;
    }

    // In any order, because GitHub does not promise one, and sorted as strings, because a numeric sort holds `1` and `01` equal and leaves them in the order they came.
    /**
     * @param list<string> $labels
     */
    private function hasLabels(array $labels): bool
    {
        $own = $this->labels;
        sort($own, SORT_STRING);
        sort($labels, SORT_STRING);

        return $own === $labels;
    }

    /**
     * @param list<int>    $waitsFor
     * @param list<string> $unreadableWaits
     * @param list<int>    $closedBy
     */
    private function namesNumbers(array $waitsFor, array $unreadableWaits, array $closedBy): bool
    {
        return $this->waitsFor === $waitsFor && $this->unreadableWaits === $unreadableWaits && $this->closedBy === $closedBy;
    }

    private function moment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format(self::MOMENT);
    }

    private function instant(string $moment): DateTimeImmutable
    {
        $instant = DateTimeImmutable::createFromFormat(self::MOMENT, $moment, new DateTimeZone('UTC'));

        return false === $instant ? throw new LogicException('A stored moment is not in the stored format: '.$moment) : $instant;
    }

    private function storedVouching(): ?Vouching
    {
        return match ($this->trusted) {
            null => null,
            true => Vouching::restored($this->vouchedLabels),
            false => Vouching::untrusted(),
        };
    }
}
