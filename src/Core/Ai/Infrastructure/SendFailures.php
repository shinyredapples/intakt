<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Symfony\AI\Platform\Exception\AuthenticationException;
use NoDiscard;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;
use WeakMap;

// What each account of one call failed with as it was sent or as its answer was read, kept by the call because FailoverPlatform's own error names no cause; by class only, because the provider's message can echo a key; excluded from the container because AccountFailoverPlatform builds one per call.
#[Exclude]
final class SendFailures
{
    /**
     * @var array<string, class-string<Throwable>>
     */
    private array $failures = [];

    /**
     * @var WeakMap<Throwable, array<string, true>>
     */
    private WeakMap $seen;

    public function __construct()
    {
        $this->seen = new WeakMap();
    }

    // False when this very failure was recorded for this account before, because the call's result and its stream can both report one.
    #[NoDiscard]
    public function record(Account $account, Throwable $failure): bool
    {
        $accounts = $this->seen[$failure] ?? [];

        if (isset($accounts[$account->key()])) {
            return false;
        }

        $accounts[$account->key()] = true;
        $this->seen[$failure] = $accounts;
        $this->failures[$account->key()] = $failure::class;

        return true;
    }

    public function wasRejected(Account $account): bool
    {
        return is_a($this->failures[$account->key()] ?? '', AuthenticationException::class, true);
    }
}
