<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\OpenItems;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\StoredItem;
use Intakt\Plugin\GitHubWorkSource\Domain\TaskFailure;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

// Because an unchanged item must not be read again, the tick's one listing of a project is kept as rows, and a listing that comes back unchanged is answered from them.
final readonly class IntakeSnapshots
{
    private const string NOT_COMPLETE = 'The open issues of the project were not listed completely, so no task is picked and no wait is closed.';

    public function __construct(
        private EntityManagerInterface $entities,
        private LockFactory $locks,
        private IssueReads $reads,
        private IssueReferences $references,
        private LoggerInterface $logger,
    ) {
    }

    // Null for a listing that came back short, because an order over part of the listing would put the wrong task first and close waits that are still open.
    public function current(string $project): ?OpenItems
    {
        // Not waited for, because the listing that holds it is this tick's listing of the project.
        $lock = $this->locks->createLock('work-source-listing-'.hash('sha256', $project));
        if (!$lock->acquire(false)) {
            $this->logger->warning('Another listing of the project is running, so this one reads nothing and no task is picked.', ['project' => $project]);

            return null;
        }

        try {
            return $this->synced($project, $this->ownUnitOfWork());
        } catch (ForgeReadFailed|ForgeRateLimited $failure) {
            $this->logger->error(self::NOT_COMPLETE, ['project' => $project, 'exception' => $failure]);

            return null;
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    private function synced(string $project, EntityManagerInterface $entities): ?OpenItems
    {
        $listing = $this->reads->listOpen($project, $entities->find(IntakeValidator::class, $project)?->value());
        if (!$listing->isUnchanged() && !$this->namesEachItemOnce($listing)) {
            $this->logger->error(self::NOT_COMPLETE, ['project' => $project, 'reason' => 'an item was listed twice, so the pages shifted while they were read and another item may have been left out']);

            return null;
        }

        $rows = $listing->isUnchanged() ? $this->rowsOf($project, $entities) : $this->record($project, $listing, $entities);
        $entities->flush();

        return new OpenItems(array_map($this->queued(...), $rows), $this->failuresOf($project, $entities));
    }

    // Its own unit of work for every tick, so the tick writes only its listing, a short listing leaves the caller's pending work for the caller's flush, and a worker that keeps its entity manager between ticks never answers from rows another listing has replaced since.
    private function ownUnitOfWork(): EntityManagerInterface
    {
        return new EntityManager($this->entities->getConnection(), $this->entities->getConfiguration(), $this->entities->getEventManager());
    }

    private function namesEachItemOnce(IssueListing $listing): bool
    {
        $numbers = array_map(static fn (ListedIssue $issue): int => $issue->number, $listing->issues());

        return \count($numbers) === \count(array_unique($numbers));
    }

    /**
     * @return list<StoredItem>
     */
    private function record(string $project, IssueListing $listing, EntityManagerInterface $entities): array
    {
        $rows = [];
        foreach ($this->rowsOf($project, $entities) as $row) {
            $rows[$row->number()] = $row;
        }

        $kept = [];
        foreach ($listing->issues() as $listed) {
            $kept[$listed->number] = $this->seen($project, $rows[$listed->number] ?? null, $listed, $entities);
        }

        // The failure of a closed task stays in its own table, so its dependents stay held.
        foreach (array_diff_key($rows, $kept) as $closed) {
            $entities->remove($closed);
        }

        $this->recordValidator($project, $listing->validator, $entities);

        return array_values($kept);
    }

    private function seen(string $project, ?StoredItem $row, ListedIssue $listed, EntityManagerInterface $entities): StoredItem
    {
        if (!$row instanceof StoredItem) {
            $row = new StoredItem($project, $listed->number, $listed);
            $entities->persist($row);

            return $row;
        }

        if ($row->isStillAt($listed)) {
            $row->dueOn($listed->milestoneDue);

            return $row;
        }

        $row->see($listed);

        return $row;
    }

    // Read again from the text on every tick and never stored, so the eligibility label and the reference rules of today apply to rows stored before.
    private function queued(StoredItem $row): QueuedIssue
    {
        $listed = $row->listed();

        return new QueuedIssue(new ItemReading($listed, $this->references->waitsFor($listed->body), $this->references->unreadableWaits($listed->body), $this->references->closedBy($listed->body)));
    }

    /**
     * @return array<int, string>
     */
    private function failuresOf(string $project, EntityManagerInterface $entities): array
    {
        $failures = [];
        foreach ($entities->getRepository(TaskFailure::class)->findBy(['project' => $project]) as $failure) {
            $failures[$failure->number()] = $failure->reason();
        }

        return $failures;
    }

    // A forge that sends no validator leaves none stored, so the next listing is a full one.
    private function recordValidator(string $project, ?string $validator, EntityManagerInterface $entities): void
    {
        $stored = $entities->find(IntakeValidator::class, $project);
        if (null === $validator) {
            if (null !== $stored) {
                $entities->remove($stored);
            }

            return;
        }

        if (null === $stored) {
            $entities->persist(new IntakeValidator($project, $validator));

            return;
        }

        $stored->replace($validator);
    }

    /**
     * @return list<StoredItem>
     */
    private function rowsOf(string $project, EntityManagerInterface $entities): array
    {
        return $entities->getRepository(StoredItem::class)->findBy(['project' => $project]);
    }
}
