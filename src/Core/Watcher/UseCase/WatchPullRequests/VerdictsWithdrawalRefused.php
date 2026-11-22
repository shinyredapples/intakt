<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use RuntimeException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;

final class VerdictsWithdrawalRefused extends RuntimeException
{
    // The round stays out of the message, because Budget compares the class and message of two pull requests' failures to find one cause behind both.
    public static function because(Throwable $refused): self
    {
        $reason = self::reasonOf($refused);

        return new self(sprintf('Review refused to withdraw the verdicts of the head\'s round: %s: %s', $reason::class, $reason->getMessage()), 0, $refused);
    }

    // Unwrapped, because the command bus's wrapper hides the handler's own exception, which is the one that says why Review refused.
    private static function reasonOf(Throwable $refused): Throwable
    {
        return $refused instanceof HandlerFailedException ? array_first($refused->getWrappedExceptions()) ?? $refused : $refused;
    }
}
