<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

final class BuildCheckoutFailed extends RuntimeException implements ReportableFailure
{
    public static function because(Throwable $failure): self
    {
        return new self($failure->getMessage(), 0, $failure);
    }

    // Fixed words, and never the caught exception as the cause, because its message holds the whole command line.
    public static function stopped(ExceptionInterface $stopped): self
    {
        return new self(match (true) {
            $stopped instanceof ProcessTimedOutException => sprintf('git timed out after %s seconds', $stopped->getExceededTimeout()),
            $stopped instanceof ProcessSignaledException => sprintf('git was killed by signal %d', $stopped->getSignal()),
            default => 'git could not run',
        });
    }
}
