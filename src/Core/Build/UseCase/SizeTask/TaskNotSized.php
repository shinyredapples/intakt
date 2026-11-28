<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\SizeTask;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class TaskNotSized extends RuntimeException implements ReportableFailure
{
    public static function unreadable(string $project, int $task, string $reason): self
    {
        return new self(sprintf('Issue %d of project "%s" cannot be sized: %s', $task, $project, $reason));
    }

    public static function notRecorded(string $project, int $task, string $reason): self
    {
        return new self(sprintf('The ledger did not record the sizing of issue %d of project "%s": %s', $task, $project, $reason));
    }

    public static function notParked(string $project, int $task): self
    {
        return new self(sprintf('Issue %d of project "%s" is not ready, and parking it failed.', $task, $project));
    }

    public static function notCommented(string $project, int $task, string $reason): self
    {
        return new self(sprintf('Issue %d of project "%s" is parked, but its comment was not posted: %s', $task, $project, $reason));
    }

    public static function stillParked(string $project, int $task): self
    {
        return new self(sprintf('Issue %d of project "%s" is ready, but its earlier readiness question was not removed.', $task, $project));
    }

    public static function notSplit(string $project, int $task, string $reason): self
    {
        return new self(sprintf('The split of issue %d of project "%s" stopped; running the command again finishes it from its plan: %s', $task, $project, $reason));
    }

    public static function planUnreadable(string $project, int $task): self
    {
        return new self(sprintf('Issue %d of project "%s" has a split plan on record that cannot be read, so it is neither split again nor finished.', $task, $project));
    }

    public static function busy(string $project, int $task): self
    {
        return new self(sprintf('Issue %d of project "%s" is not sized: another sizing of it is running.', $task, $project));
    }
}
