<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;

// What each account of one call failed with as it was sent or as its answer was read, kept by the call because FailoverPlatform's own error names no cause; by class only, because the provider's message can echo a key; excluded from the container because AccountFailoverPlatform builds one per call.
#[Exclude]
final class SendFailures
{
    /**
     * @var array<string, class-string<Throwable>>
     */
    private array $failures = [];

    public function record(Account $account, Throwable $failure): void
    {
        $this->failures[$account->key()] = $failure::class;
    }

    public function wasRejected(Account $account): bool
    {
        return is_a($this->failures[$account->key()] ?? '', AuthenticationException::class, true);
    }
}
