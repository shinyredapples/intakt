<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\StoredIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\TaskFailure;
use Intakt\Plugin\WorkSource\Contract\TaskNotQueued;
use InvalidArgumentException;

// Every method reads and leaves the project's rows detached, because a worker keeps its entity manager between ticks and what another worker stored since is the truth.
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
        $this->forgetRowsOf($project);
        $issues = $this->storedIssuesOf($project);
        $this->forgetRowsOf($project);

        return array_values($issues);
    }

    public function validatorOf(string $project): ?string
    {
        $this->forgetRowsOf($project);
        $validator = $this->entities->find(IntakeValidator::class, $project)?->value();
        $this->forgetRowsOf($project);

        return $validator;
    }

    // The rows become those of one complete listing, so an item it no longer shows was closed and its row goes; a forge that sent no validator leaves none stored, so the next listing is a full one.
    /**
     * @param list<StoredIssue> $issues
     */
    public function record(string $project, array $issues, ?string $validator): void
    {
        $this->forgetRowsOf($project);
        try {
            $stored = $this->storedIssuesOf($project);
            $listed = [];
            foreach ($issues as $issue) {
                if ($issue->project() !== $project) {
                    throw new InvalidArgumentException(sprintf('Issue #%d belongs to project "%s", not to "%s".', $issue->number(), $issue->project(), $project));
                }

                $listed[$issue->number()] = $issue;
            }

            foreach ($listed as $number => $issue) {
                $this->keep($stored[$number] ?? null, $issue);
            }

            foreach (array_diff_key($stored, $listed) as $closed) {
                $this->entities->remove($closed);
            }

            $this->recordValidator($project, $validator);
            $this->entities->flush();
        } finally {
            // Detached on every way out, so a write that failed midway leaves nothing for a later flush to write.
            $this->forgetRowsOf($project);
        }
    }

    // Apart from the issue rows, so a task that is closed after it failed for good still holds the tasks that wait on it.
    public function failForGood(string $project, int $number, string $reason): void
    {
        $this->forgetRowsOf($project);
        try {
            if (!$this->entities->find(StoredIssue::class, ['project' => $project, 'number' => $number]) instanceof StoredIssue) {
                throw TaskNotQueued::named($project, $number);
            }

            $this->recordFailure($project, $number, $reason);
            $this->entities->flush();
        } finally {
            $this->forgetRowsOf($project);
        }
    }

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

        $this->forgetRowsOf($project);

        return $failures;
    }

    /**
     * @return array<int, StoredIssue>
     */
    private function storedIssuesOf(string $project): array
    {
        $issues = [];
        foreach ($this->entities->getRepository(StoredIssue::class)->findBy(['project' => $project], ['number' => 'ASC']) as $issue) {
            $issues[$issue->number()] = $issue;
        }

        return $issues;
    }

    private function keep(?StoredIssue $stored, StoredIssue $issue): void
    {
        if ($stored instanceof StoredIssue) {
            $stored->replaceWith($issue);

            return;
        }

        $this->entities->persist($issue);
    }

    private function recordFailure(string $project, int $number, string $reason): void
    {
        $failure = $this->entities->find(TaskFailure::class, ['project' => $project, 'number' => $number]);
        if ($failure instanceof TaskFailure) {
            $failure->replace($reason);

            return;
        }

        $this->entities->persist(new TaskFailure($project, $number, $reason));
    }

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

    // Detached one by one, never cleared, because the caller's own pending work in the entity manager must survive.
    private function forgetRowsOf(string $project): void
    {
        foreach ($this->entities->getUnitOfWork()->getIdentityMap() as $managed) {
            foreach ($managed as $entity) {
                if (($entity instanceof StoredIssue || $entity instanceof IntakeValidator || $entity instanceof TaskFailure) && $entity->project() === $project) {
                    $this->entities->detach($entity);
                }
            }
        }
    }
}
