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
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use InvalidArgumentException;
use Symfony\Component\Lock\LockFactory;

// The tick's one listing of a project, kept as rows: an item the listing shows unchanged is not read again, and a listing that comes back unchanged is answered from them.
final readonly class IntakeSnapshots
{
    private const string IDEA = 'idea';

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
     * @return list<QueuedIssue> every open issue and pull request of the project, from one complete listing
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
            ?? throw new InvalidArgumentException(sprintf('Project "%s" has no open task #%d.', $project, $number));
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
        $changes = [];
        foreach ($this->uniqueByNumber($listing->issues()) as $listed) {
            $row = $rows[$listed->number] ?? null;
            if (null !== $row && $row->isStillAt($listed)) {
                $kept[$listed->number] = $row;

                continue;
            }

            $changes[] = [$listed, ...$this->trustOf($project, $listed)];
        }

        // Written only once every read and trust check passed, so a failure midway leaves the rows as the last complete listing left them.
        foreach ($changes as [$listed, $editor, $trusted]) {
            $waits = $this->references->waitsFor($listed->body);
            $closes = $this->references->closedBy($listed->body);
            $row = $rows[$listed->number] ?? null;
            if (null === $row) {
                $row = new QueuedIssue($project, $listed->number, $listed, $editor, $trusted, $waits, $closes);
                $this->entities->persist($row);
            } else {
                $row->see($listed, $editor, $trusted, $waits, $closes);
            }
            $kept[$listed->number] = $row;
        }

        foreach (array_diff_key($rows, $kept) as $closed) {
            $this->entities->remove($closed);
        }

        $this->recordValidator($project, $listing->validator);
        $this->entities->flush();

        return array_values($kept);
    }

    /**
     * @return array{?string, bool}
     */
    private function trustOf(string $project, ListedIssue $listed): array
    {
        $isTask = !$listed->isPullRequest && in_array($this->labels->of($project), $listed->labels, true) && !in_array(self::IDEA, $listed->labels, true);
        if (!$isTask) {
            return [null, false];
        }

        $editor = $this->reads->lastEditorOf($project, $listed->number);
        $trusted = $this->accounts->steers($project, $listed->author) && (0 === strcasecmp($editor, $listed->author) || $this->accounts->steers($project, $editor));

        return [$editor, $trusted];
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
        return array_values($this->entities->getRepository(QueuedIssue::class)->findBy(['project' => $project]));
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
