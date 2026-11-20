<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\StoredIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\TaskFailure;
use Intakt\Plugin\WorkSource\Contract\TaskNotQueued;
use InvalidArgumentException;

// Every call works in an entity manager of its own on the shared connection: a worker keeps the shared one between ticks, what another worker stored since is the truth, a write must flush only its own rows and never another caller's pending work, and a write that fails leaves nothing behind for a later flush.
final readonly class IntakeStore
{
    public function __construct(
        private EntityManagerInterface $entities,
    ) {
    }

    /**
     * @return list<StoredIssue>
     */
    public function issuesOf(string $project): array
    {
        return array_values($this->storedIssuesOf($this->ownManager(), $project));
    }

    public function validatorOf(string $project): ?string
    {
        return $this->ownManager()->find(IntakeValidator::class, $project)?->value();
    }

    // The rows become those of one complete listing, so an item it no longer shows was closed and its row goes; a forge that sent no validator leaves none stored, so the next listing is a full one.
    /**
     * @param list<StoredIssue> $issues
     */
    public function record(string $project, array $issues, ?string $validator): void
    {
        $listed = [];
        foreach ($issues as $issue) {
            if ($issue->project() !== $project) {
                throw new InvalidArgumentException(sprintf('Issue #%d belongs to project "%s", not to "%s".', $issue->number(), $issue->project(), $project));
            }

            $listed[$issue->number()] = $issue;
        }

        $this->ownManager()->wrapInTransaction(function (EntityManagerInterface $manager) use ($project, $listed, $validator): void {
            $stored = $this->storedIssuesOf($manager, $project);
            foreach ($listed as $number => $issue) {
                $this->keep($manager, $stored[$number] ?? null, $issue);
            }

            foreach (array_diff_key($stored, $listed) as $closed) {
                $manager->remove($closed);
            }

            $this->recordValidator($manager, $project, $validator);
        });
    }

    // Apart from the issue rows, so a task that is closed after it failed for good still holds the tasks that wait on it.
    public function failForGood(string $project, int $number, string $reason): void
    {
        $this->ownManager()->wrapInTransaction(static function (EntityManagerInterface $manager) use ($project, $number, $reason): void {
            if (!$manager->find(StoredIssue::class, ['project' => $project, 'number' => $number]) instanceof StoredIssue) {
                throw TaskNotQueued::named($project, $number);
            }

            $failure = $manager->find(TaskFailure::class, ['project' => $project, 'number' => $number]);
            if ($failure instanceof TaskFailure) {
                $failure->replace($reason);

                return;
            }

            $manager->persist(new TaskFailure($project, $number, $reason));
        });
    }

    /**
     * @return array<int, string>
     */
    public function failuresOf(string $project): array
    {
        $failures = [];
        foreach ($this->ownManager()->getRepository(TaskFailure::class)->findBy(['project' => $project]) as $failure) {
            $failures[$failure->number()] = $failure->reason();
        }

        return $failures;
    }

    private function ownManager(): EntityManagerInterface
    {
        return new EntityManager($this->entities->getConnection(), $this->entities->getConfiguration(), $this->entities->getEventManager());
    }

    /**
     * @return array<int, StoredIssue>
     */
    private function storedIssuesOf(EntityManagerInterface $manager, string $project): array
    {
        $issues = [];
        foreach ($manager->getRepository(StoredIssue::class)->findBy(['project' => $project], ['number' => 'ASC']) as $issue) {
            $issues[$issue->number()] = $issue;
        }

        return $issues;
    }

    private function keep(EntityManagerInterface $manager, ?StoredIssue $stored, StoredIssue $issue): void
    {
        if ($stored instanceof StoredIssue) {
            $stored->replaceWith($issue);

            return;
        }

        $manager->persist($issue);
    }

    private function recordValidator(EntityManagerInterface $manager, string $project, ?string $validator): void
    {
        $stored = $manager->find(IntakeValidator::class, $project);
        if (null === $validator) {
            if (null !== $stored) {
                $manager->remove($stored);
            }

            return;
        }

        if (null === $stored) {
            $manager->persist(new IntakeValidator($project, $validator));

            return;
        }

        $stored->replace($validator);
    }
}
