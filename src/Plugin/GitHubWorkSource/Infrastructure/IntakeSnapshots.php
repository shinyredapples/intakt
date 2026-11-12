<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeRules;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\TaskFailure;
use Intakt\Plugin\GitHubWorkSource\Domain\Vouching;
use Intakt\Plugin\WorkSource\Contract\TaskNotQueued;
use Symfony\Component\Lock\LockFactory;

// Because an unchanged item must not be read again, the tick's one listing of a project is kept as rows, and a listing that comes back unchanged is answered from them.
final readonly class IntakeSnapshots
{
    public function __construct(
        private EntityManagerInterface $entities,
        private LockFactory $locks,
        private IssueReads $reads,
        private SteeringAccounts $accounts,
        private IssueReferences $references,
        private EligibilityLabels $labels,
    ) {
    }

    /**
     * @return list<QueuedIssue>
     *
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     * @throws TrustNotChecked
     */
    public function current(string $project): array
    {
        // Held across the listing because two listings of one project would race on the rows; not waited for, because the other one is the tick's listing.
        $lock = $this->locks->createLock('work-source-listing-'.hash('sha256', $project));
        if (!$lock->acquire(false)) {
            throw new ForgeReadFailed('Another listing of this project is running.');
        }

        try {
            $this->forgetRowsOf($project);
            $listing = $this->reads->listOpen($project, $this->entities->find(IntakeValidator::class, $project)?->value());
            $rows = $listing->isUnchanged() ? $this->rowsOf($project) : $this->record($project, $listing);
            $this->readHistories($project, $rows);
            $this->entities->flush();

            return $rows;
        } finally {
            // Detached on every way out, so a read that failed midway leaves nothing of this listing for a later flush to write.
            $this->forgetRowsOf($project);
            $lock->release();
        }
    }

    public function failForGood(string $project, int $number, string $reason): void
    {
        $this->forgetRowsOf($project);
        if (!$this->entities->find(QueuedIssue::class, ['project' => $project, 'number' => $number]) instanceof QueuedIssue) {
            throw TaskNotQueued::named($project, $number);
        }

        $failure = $this->entities->find(TaskFailure::class, ['project' => $project, 'number' => $number]);
        if ($failure instanceof TaskFailure) {
            $failure->replace($reason);
        } else {
            $this->entities->persist(new TaskFailure($project, $number, $reason));
        }

        $this->entities->flush();
    }

    // Read from the database on each call, because another worker may record a failure while this one plans.
    /**
     * @return array<int, string>
     */
    public function failuresOf(string $project): array
    {
        $this->forgetRowsOf($project);
        $failures = [];
        foreach ($this->entities->getRepository(TaskFailure::class)->findBy(['project' => $project]) as $failure) {
            $failures[$failure->number()] = $failure->reason();
        }

        return $failures;
    }

    /**
     * @return list<QueuedIssue>
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

        foreach (array_diff_key($rows, $kept) as $closed) {
            $this->entities->remove($closed);
        }

        $this->recordValidator($project, $listing->validator);

        return array_values($kept);
    }

    private function seen(string $project, ?QueuedIssue $row, ListedIssue $listed): QueuedIssue
    {
        if (!$row instanceof QueuedIssue) {
            $row = new QueuedIssue($project, $listed->number, $this->reading($listed));
            $this->entities->persist($row);

            return $row;
        }

        if ($row->isStillAt($listed)) {
            $row->dueOn($listed->milestoneDue);

            return $row;
        }

        $row->see($this->reading($listed));

        return $row;
    }

    private function reading(ListedIssue $listed): ItemReading
    {
        return new ItemReading($listed, $this->references->waitsFor($listed->body), $this->references->closedBy($listed->body));
    }

    // Also for a row the listing shows unchanged, because a project that names another eligibility label makes candidates of items read before as none.
    /**
     * @param list<QueuedIssue> $rows
     */
    private function readHistories(string $project, array $rows): void
    {
        $rules = new IntakeRules($this->labels->of($project));
        foreach ($rows as $row) {
            if (!$row->hasHistory() && $rules->needsHistory($row)) {
                $row->vouch($this->vouchingOf($project, $row));
            }
        }
    }

    private function vouchingOf(string $project, QueuedIssue $row): Vouching
    {
        $history = $this->reads->historyOf($project, $row->number());
        $asked = [];
        // Asked once per account and item, and logins compared without case, because GitHub treats them so.
        $steers = function (string $login) use ($project, &$asked): bool {
            return $asked[mb_strtolower($login)] ??= $this->accounts->steers($project, $login);
        };

        if (!$steers($row->author()) || !$steers($history->editor)) {
            return new Vouching($history->editor, false, []);
        }

        $vouched = array_filter($history->labelAppliers, static fn (?string $login): bool => null !== $login && $steers($login));

        return new Vouching($history->editor, true, array_map(strval(...), array_keys($vouched)));
    }

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
     * @return list<QueuedIssue>
     */
    private function rowsOf(string $project): array
    {
        return $this->entities->getRepository(QueuedIssue::class)->findBy(['project' => $project]);
    }

    // Detached one by one, never cleared, because a worker keeps its entity manager between ticks and what another listing stored since is the truth, while the caller's own pending work must survive.
    private function forgetRowsOf(string $project): void
    {
        foreach ($this->entities->getUnitOfWork()->getIdentityMap() as $managed) {
            foreach ($managed as $entity) {
                if (($entity instanceof QueuedIssue || $entity instanceof IntakeValidator || $entity instanceof TaskFailure) && $entity->project() === $project) {
                    $this->entities->detach($entity);
                }
            }
        }
    }
}
