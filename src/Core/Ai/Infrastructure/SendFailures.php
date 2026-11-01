<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;

// What the accounts of one call failed with as it was sent, kept by the call because FailoverPlatform's own error names no cause; excluded from the container because AccountFailoverPlatform builds one per call.
#[Exclude]
final class SendFailures
{
    /**
     * @var list<Throwable>
     */
    private array $failures = [];

    public function record(Throwable $failure): void
    {
        $this->failures[] = $failure;
    }

    // Only when every account of the provider was tried and rejected its key, because an account cooling down from before the call, or limited during it, is free again after its wait and a rejected key never is.
    public function rejectionOfEveryAccount(int $accounts): ?AuthenticationException
    {
        $last = array_last($this->failures);

        return $last instanceof AuthenticationException
            && $accounts === count($this->failures)
            && array_all($this->failures, static fn (Throwable $failure): bool => $failure instanceof AuthenticationException) ? $last : null;
    }
}
