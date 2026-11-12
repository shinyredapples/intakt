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
use Intakt\Plugin\GitHubWorkSource\Contract\WorkLabels;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
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

            return $listing->isUnchanged() ? $this->rowsOf($project) : $this->record($project, $listing);
        } finally {
            $lock->release();
        }
    }

    public function failForGood(string $project, int $number, string $reason): void
    {
        $this->forgetRowsOf($project);
        $row = $this->entities->find(QueuedIssue::class, ['project' => $project, 'number' => $number])
            ?? throw TaskNotQueued::named($project, $number);
        $row->failForGood($reason);
        $this->entities->flush();
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
        $readings = [];
        foreach ($this->uniqueByNumber($listing->issues()) as $listed) {
            $row = $rows[$listed->number] ?? null;
            if (null !== $row && $row->isStillAt($listed)) {
                $kept[$listed->number] = $row;

                continue;
            }

            $readings[] = $this->reading($project, $listed);
        }

        // Written only once every read and trust check passed, so a failure midway leaves the rows as the last complete listing left them.
        foreach ($readings as $reading) {
            $kept[$reading->listed->number] = $this->store($project, $rows[$reading->listed->number] ?? null, $reading);
        }

        foreach (array_diff_key($rows, $kept) as $closed) {
            $this->entities->remove($closed);
        }

        $this->recordValidator($project, $listing->validator);
        $this->entities->flush();

        return array_values($kept);
    }

    private function store(string $project, ?QueuedIssue $row, ItemReading $reading): QueuedIssue
    {
        if (null === $row) {
            $row = new QueuedIssue($project, $reading);
            $this->entities->persist($row);

            return $row;
        }

        $row->see($reading);

        return $row;
    }

    private function reading(string $project, ListedIssue $listed): ItemReading
    {
        $waits = $this->references->waitsFor($listed->body);
        $closes = $this->references->closedBy($listed->body);
        $isTask = !$listed->isPullRequest && in_array($this->labels->of($project), $listed->labels, true) && !in_array(WorkLabels::IDEA, $listed->labels, true);
        if (!$isTask) {
            return new ItemReading($listed, null, false, $waits, $closes);
        }

        $editor = $this->reads->lastEditorOf($project, $listed->number);
        $trusted = $this->accounts->steers($project, $listed->author) && (0 === strcasecmp($editor, $listed->author) || $this->accounts->steers($project, $editor));

        return new ItemReading($listed, $editor, $trusted, $waits, $closes);
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
                if (($entity instanceof QueuedIssue || $entity instanceof IntakeValidator) && $entity->project() === $project) {
                    $this->entities->detach($entity);
                }
            }
        }
    }
}
