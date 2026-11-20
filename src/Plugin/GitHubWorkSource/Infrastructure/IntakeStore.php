<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeState;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\StoredIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\TaskFailure;
use Intakt\Plugin\GitHubWorkSource\Domain\ValidatorChange;
use Intakt\Plugin\WorkSource\Contract\TaskNotQueued;
use InvalidArgumentException;
use LogicException;

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

    public function stateOf(string $project): IntakeState
    {
        return $this->ownManager()->find(IntakeValidator::class, $project)?->state() ?? IntakeState::none();
    }

    // The generation and not the validator is compared under the row's lock, because a sync that committed meanwhile holds newer rows also when neither listing had a validator to compare.
    /**
     * @param list<StoredIssue> $issues
     */
    public function recordIfStill(string $project, array $issues, ValidatorChange $change): bool
    {
        $listed = $this->listed($project, $issues);
        try {
            return true === $this->ownManager()->wrapInTransaction(function (EntityManagerInterface $manager) use ($project, $listed, $change): bool {
                $stored = $manager->find(IntakeValidator::class, $project, LockMode::PESSIMISTIC_WRITE) ?? $this->firstStateOf($manager, $project);
                if ($stored->state()->generation !== $change->from->generation) {
                    return false;
                }

                $this->replaceRows($manager, $project, $listed);
                $stored->advance($change->to);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // A first sync that committed the project's state row while this one wrote its own holds the newer rows, so this one loses like any other stale write.
            return false;
        }
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
        foreach ($this->rowsOf($this->ownManager(), TaskFailure::class, $project) as $failure) {
            $failures[$failure->number()] = $failure->reason();
        }

        return $failures;
    }

    /**
     * @return array<int, StoredIssue>
     */
    private function storedIssuesOf(EntityManagerInterface $manager, string $project): array
    {
        $issues = [];
        foreach ($this->rowsOf($manager, StoredIssue::class, $project) as $issue) {
            $issues[$issue->number()] = $issue;
        }

        return $issues;
    }

    private function ownManager(): EntityManagerInterface
    {
        // Without the shared event manager the mapping's listeners would not run, so another kind is refused instead of replaced.
        $events = $this->entities->getEventManager();
        if (!$events instanceof EventManager) {
            throw new LogicException(sprintf('The intake store needs a %s, %s given.', EventManager::class, $events::class));
        }

        return new EntityManager($this->entities->getConnection(), $this->entities->getConfiguration(), $events);
    }

    /**
     * @param list<StoredIssue> $issues
     *
     * @return array<int, StoredIssue>
     */
    private function listed(string $project, array $issues): array
    {
        $listed = [];
        foreach ($issues as $issue) {
            if ($issue->project() !== $project) {
                throw new InvalidArgumentException(sprintf('Issue #%d belongs to project "%s", not to "%s".', $issue->number(), $issue->project(), $project));
            }

            $listed[$issue->number()] = $issue;
        }

        return $listed;
    }

    private function firstStateOf(EntityManagerInterface $manager, string $project): IntakeValidator
    {
        $state = new IntakeValidator($project, null);
        $manager->persist($state);

        return $state;
    }

    // An item the listing no longer shows was closed, so its row goes; its failure stays in a table of its own.
    /**
     * @param array<int, StoredIssue> $listed
     */
    private function replaceRows(EntityManagerInterface $manager, string $project, array $listed): void
    {
        $stored = $this->storedIssuesOf($manager, $project);
        foreach ($listed as $number => $issue) {
            $this->keep($manager, $stored[$number] ?? null, $issue);
        }

        foreach (array_diff_key($stored, $listed) as $closed) {
            $manager->remove($closed);
        }
    }

    private function keep(EntityManagerInterface $manager, ?StoredIssue $stored, StoredIssue $issue): void
    {
        if ($stored instanceof StoredIssue) {
            $stored->replaceWith($issue);

            return;
        }

        $manager->persist($issue);
    }

    // A query and not a repository: the shared configuration's repository factory keeps every manager it built a repository for, with the rows that manager loaded, for the life of the worker.
    /**
     * @template T of StoredIssue|TaskFailure
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function rowsOf(EntityManagerInterface $manager, string $class, string $project): array
    {
        $rows = $manager->createQueryBuilder()
            ->select('row')
            ->from($class, 'row')
            ->where('row.project = :project')
            ->orderBy('row.number', 'ASC')
            ->setParameter('project', $project)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(is_array($rows) ? $rows : [], static fn (mixed $row): bool => $row instanceof $class));
    }
}
