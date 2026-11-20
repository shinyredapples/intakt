<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
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

        return new QueuedIssue($reading, null === $this->trusted ? null : new Vouching($this->trusted, $this->vouchedLabels));
    }

    public function project(): string
    {
        return $this->project;
    }

    public function number(): int
    {
        return $this->number;
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
}
