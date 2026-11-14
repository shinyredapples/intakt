<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class TaskOutcome
{
    /**
     * @param list<TaskStep> $completed
     */
    private function __construct(
        public array $completed,
        public ?TaskStep $failedAt,
        public ?string $reason,
        public ?string $directory,
    ) {
    }

    /**
     * @param list<TaskStep> $completed
     */
    public static function stoppedAt(array $completed, TaskStep $step, string $reason): self
    {
        return new self($completed, $step, $reason, null);
    }

    /**
     * @param list<TaskStep> $completed
     */
    public static function cloned(array $completed, string $directory): self
    {
        return new self($completed, null, null, $directory);
    }

    public function passed(): bool
    {
        return null === $this->failedAt;
    }
}
