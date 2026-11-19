<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use LogicException;

// One open issue or pull request as the last complete listing showed it, so a listing that shows it unchanged is answered from here.
#[ORM\Entity]
#[ORM\Table(name: 'work_source_issue')]
final class StoredItem
{
    // Text rather than a datetime column, because the platforms' datetime columns keep whole seconds and an update within the same second would then go unseen.
    private const string MOMENT = 'Y-m-d H:i:s.u';

    #[ORM\Column(type: 'text')]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $body;

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

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private readonly string $project,
        #[ORM\Id]
        #[ORM\Column]
        private readonly int $number,
        ListedIssue $listed,
    ) {
        $this->see($listed);
    }

    public function isStillAt(ListedIssue $listed): bool
    {
        return $this->updatedAt === self::momentOf($listed->updatedAt);
    }

    public function see(ListedIssue $listed): void
    {
        $this->title = $listed->title;
        $this->body = $listed->body;
        $this->labels = $listed->labels;
        $this->author = $listed->author;
        $this->updatedAt = self::momentOf($listed->updatedAt);
        $this->pullRequest = $listed->isPullRequest;
        $this->dueOn($listed->milestoneDue);
    }

    // Taken from every listing, because GitHub changes a milestone's due date without moving the issue's updated_at.
    public function dueOn(?DateTimeImmutable $due): void
    {
        $this->milestoneDue = null === $due ? null : self::momentOf($due);
    }

    public function listed(): ListedIssue
    {
        return new ListedIssue(
            $this->number,
            $this->title,
            $this->body,
            $this->labels,
            $this->author,
            self::moment($this->updatedAt),
            null === $this->milestoneDue ? null : self::moment($this->milestoneDue),
            $this->pullRequest,
        );
    }

    public function project(): string
    {
        return $this->project;
    }

    public function number(): int
    {
        return $this->number;
    }

    private static function momentOf(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format(self::MOMENT);
    }

    private static function moment(string $stored): DateTimeImmutable
    {
        $moment = DateTimeImmutable::createFromFormat(self::MOMENT, $stored, new DateTimeZone('UTC'));

        return false === $moment ? throw new LogicException('A stored moment is not one: '.$stored) : $moment;
    }
}
