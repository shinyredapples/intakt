<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

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
            return $this->synced($project);
        } catch (ForgeReadFailed|ForgeRateLimited $failure) {
            $this->logger->error('The open issues of the project were not listed completely, so no task is picked and no wait is closed.', ['project' => $project, 'exception' => $failure]);

            return null;
        } finally {
            // Detached on every way out, so a read that failed midway leaves nothing of this listing for a later flush to write.
            $this->forgetRowsOf($project);
            $lock->release();
        }
    }

    /**
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    private function synced(string $project): OpenItems
    {
        $this->forgetRowsOf($project);
        $listing = $this->reads->listOpen($project, $this->entities->find(IntakeValidator::class, $project)?->value());
        $rows = $listing->isUnchanged() ? $this->rowsOf($project) : $this->record($project, $listing);
        $this->entities->flush();

        return new OpenItems(array_map($this->queued(...), $rows), $this->failuresOf($project));
    }

    /**
     * @return list<StoredItem>
     */
    private function record(string $project, IssueListing $listing): array
    {
        $rows = [];
        foreach ($this->rowsOf($project) as $row) {
            $rows[$row->number()] = $row;
        }

        $kept = [];
        foreach ($this->uniqueByNumber($listing->issues()) as $listed) {
            $kept[$listed->number] = $this->seen($project, $rows[$listed->number] ?? null, $listed);
        }

        // The failure of a closed task stays in its own table, so its dependents stay held.
        foreach (array_diff_key($rows, $kept) as $closed) {
            $this->entities->remove($closed);
        }

        $this->recordValidator($project, $listing->validator);

        return array_values($kept);
    }

    private function seen(string $project, ?StoredItem $row, ListedIssue $listed): StoredItem
    {
        if (!$row instanceof StoredItem) {
            $row = new StoredItem($project, $listed->number, $listed);
            $this->entities->persist($row);

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
    private function failuresOf(string $project): array
    {
        $failures = [];
        foreach ($this->entities->getRepository(TaskFailure::class)->findBy(['project' => $project]) as $failure) {
            $failures[$failure->number()] = $failure->reason();
        }

        return $failures;
    }

    // The last one listed wins, because a page that shifts while it is read can show an item twice.
    /**
     * @param list<ListedIssue> $issues
     *
     * @return list<ListedIssue>
     */
    private function uniqueByNumber(array $issues): array
    {
        $unique = [];
        foreach ($issues as $issue) {
            $unique[$issue->number] = $issue;
        }

        return array_values($unique);
    }

    // A forge that sends no validator leaves none stored, so the next listing is a full one.
    private function recordValidator(string $project, ?string $validator): void
    {
        $stored = $this->entities->find(IntakeValidator::class, $project);
        if (null === $validator) {
            if (null !== $stored) {
                $this->entities->remove($stored);
            }

            return;
        }

        if (null === $stored) {
            $this->entities->persist(new IntakeValidator($project, $validator));

            return;
        }

        $stored->replace($validator);
    }

    /**
     * @return list<StoredItem>
     */
    private function rowsOf(string $project): array
    {
        return $this->entities->getRepository(StoredItem::class)->findBy(['project' => $project]);
    }

    // Detached one by one, never cleared, because a worker keeps its entity manager between ticks and what another listing stored since is the truth, while the caller's own pending work must survive.
    private function forgetRowsOf(string $project): void
    {
        foreach ($this->entities->getUnitOfWork()->getIdentityMap() as $managed) {
            foreach ($managed as $entity) {
                if (($entity instanceof StoredItem || $entity instanceof IntakeValidator || $entity instanceof TaskFailure) && $entity->project() === $project) {
                    $this->entities->detach($entity);
                }
            }
        }
    }
}
