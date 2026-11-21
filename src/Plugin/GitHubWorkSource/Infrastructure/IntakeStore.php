<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\ListingRows;
use Intakt\Plugin\GitHubWorkSource\Domain\StoredIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\TaskFailure;
use Intakt\Plugin\WorkSource\Contract\TaskNotQueued;
use InvalidArgumentException;
use LogicException;
use NoDiscard;

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

    // Written only while the stored validator is still the one the listing was asked with, because otherwise another worker stored a newer listing meanwhile and these rows would replace it with an older one.
    #[NoDiscard]
    public function recordIfStill(string $project, ?string $askedWith, ListingRows $rows): bool
    {
        $listed = $this->listed($project, $rows->issues);

        return $this->ownManager()->wrapInTransaction(function (EntityManagerInterface $manager) use ($project, $askedWith, $listed, $rows): bool {
            if ($manager->find(IntakeValidator::class, $project, LockMode::PESSIMISTIC_WRITE)?->value() !== $askedWith) {
                return false;
            }

            $this->replaceRows($manager, $project, $listed);
            $this->recordValidator($manager, $project, $rows->validator);

            return true;
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
        foreach ($this->rowsOf($this->ownManager(), TaskFailure::class, $project) as $failure) {
            $failures[$failure->number()] = $failure->reason();
        }

        return $failures;
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

    // The rows become those of one complete listing, so an item it no longer shows was closed and its row goes; failures stay, because a closed task that failed for good still holds the tasks that wait on it.
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
