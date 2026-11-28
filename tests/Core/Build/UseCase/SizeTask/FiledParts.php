<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\SizeTask;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\PartIssue;
use Intakt\Plugin\GitHubForge\Contract\TaskParts;
use Override;

// The issues a split filed, kept by marker as the forge finds them, numbered from 100 in the order they were filed, so a test sees how many parts the owner would see.
final class FiledParts implements TaskParts
{
    /**
     * @var list<array{number: int, title: string, body: string, marker: string, labels: list<string>}>
     */
    public array $filed = [];

    // The create of this filing (counted from 1) is stored but answered as failed, because GitHub can answer 502 to an issue it created.
    public ?int $answerLostFor = null;

    public ?ForgeReadFailed $unreadable = null;

    // The numbers go to the ledger as the forge keeps them, because a later sizing of a part asks for them with other objects around the same ledger.
    public function __construct(private readonly Ledger $ledger)
    {
    }

    #[Override]
    public function fileOnce(string $project, PartIssue $part): int
    {
        if ($this->unreadable instanceof ForgeReadFailed) {
            throw $this->unreadable;
        }

        $found = array_find($this->filed, static fn (array $issue): bool => $issue['marker'] === $part->marker);

        if (null !== $found) {
            return $this->kept($project, $found['number']);
        }

        $number = 100 + count($this->filed);
        $this->filed[] = ['number' => $number, 'title' => $part->title, 'body' => $part->body, 'marker' => $part->marker, 'labels' => [...$part->labels]];

        if (count($this->filed) === $this->answerLostFor) {
            $this->answerLostFor = null;

            throw new ForgeWriteFailed('GitHub answered 502 to a write.');
        }

        return $this->kept($project, $number);
    }

    #[Override]
    public function isFiledPart(string $project, int $issue): bool
    {
        return array_any([...$this->ledger->readOfKindsMentioning(new Mentions(json_encode($project, JSON_THROW_ON_ERROR)), LedgerEventKind::PartIssueCreated)], static fn (RecordedEvent $kept): bool => self::lineOf($project, $issue) === $kept->event->payload);
    }

    /**
     * @return list<string>
     */
    public function titles(): array
    {
        return array_column($this->filed, 'title');
    }

    private function kept(string $project, int $number): int
    {
        if (!$this->isFiledPart($project, $number)) {
            (void) $this->ledger->append(new LedgerEvent(LedgerEventKind::PartIssueCreated, self::lineOf($project, $number)));
        }

        return $number;
    }

    private static function lineOf(string $project, int $number): string
    {
        return json_encode(['project' => $project, 'issue' => $number], JSON_THROW_ON_ERROR);
    }
}
