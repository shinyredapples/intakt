<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use Intakt\Core\Build\Contract\FinishedSplit;
use Intakt\Core\Build\Contract\SplitUmbrellas;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\KeyedComment;
use Intakt\Plugin\GitHubForge\Contract\TaskCloses;
use Intakt\Plugin\GitHubForge\Contract\TaskParts;
use Intakt\Plugin\GitHubWorkSource\Domain\ClosedUmbrellas;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
use Psr\Log\LoggerInterface;

// The parts come from the finished split's record, never from the umbrella's comment, a `Waits for` line or a label, because anyone can write those: docs/design/05-architecture/03-choosing-work.md ("A split happens once").
final readonly class UmbrellaClosing
{
    private const string COMMENT_KEY = 'umbrella-closed';

    public function __construct(
        private SplitUmbrellas $umbrellas,
        private TaskParts $parts,
        private TaskCloses $closes,
        private LoggerInterface $logger,
    ) {
    }

    // Over a complete listing only, which the plan stands for, because a part a short listing leaves out is still open.
    public function closeFinished(string $project, IntakePlan $plan): ClosedUmbrellas
    {
        $closed = [];
        $stayedOpen = [];

        foreach ($this->umbrellas->finishedOf($project)->all() as $split) {
            if (!$this->isDone($project, $split, $plan) || isset($closed[$split->umbrella]) || isset($stayedOpen[$split->umbrella])) {
                continue;
            }

            $failure = $this->failureOfClosing($project, $split);
            if (null === $failure) {
                $closed[$split->umbrella] = $split;

                continue;
            }

            $stayedOpen[$split->umbrella] = $failure;
        }

        return new ClosedUmbrellas(array_values($closed), $stayedOpen);
    }

    // Each part must be a part this project's current repository filed, because after a remap to another repository the same numbers name other issues.
    private function isDone(string $project, FinishedSplit $split, IntakePlan $plan): bool
    {
        return $plan->isOpen($split->umbrella)
            && !array_any($split->parts, $plan->isOpen(...))
            && array_all($split->parts, fn (int $part): bool => $this->parts->isFiledPart($project, $part));
    }

    // Null once closed; a refused close is only logged, because the umbrella stays in the open listing and the next tick tries again.
    private function failureOfClosing(string $project, FinishedSplit $split): ?string
    {
        $parts = implode(', ', array_map(static fn (int $part): string => '#'.$part, $split->parts));

        try {
            $this->closes->closeAsCompleted($project, $split->umbrella, new KeyedComment(sprintf('All parts are closed (%s), so this umbrella is closed.', $parts), self::COMMENT_KEY));
        } catch (ForgeReadFailed|ForgeWriteFailed|ForgeRateLimited $failure) {
            $this->logger->warning('Umbrella #{number} of project {project} has every part closed but was not closed, so the next tick tries again: {reason}', ['number' => $split->umbrella, 'project' => $project, 'reason' => $failure->getMessage(), 'exception' => $failure]);

            return $failure->getMessage();
        }

        $this->logger->info('Umbrella #{number} of project {project} is closed: its parts {parts} are closed.', ['number' => $split->umbrella, 'project' => $project, 'parts' => $parts]);

        return null;
    }
}
