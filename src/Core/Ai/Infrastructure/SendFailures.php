<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use NoDiscard;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;
use WeakMap;

// Which account error (a limit, a rejection or a provider error) each account of one call failed with as it was sent or as its answer was read, which accounts the call was sent to and which of those could not reach their endpoint as it was sent, kept by the call because FailoverPlatform's own error names no cause; by class only, because the provider's message can echo a key; excluded from the container because AccountFailoverPlatform builds one per call.
#[Exclude]
final class SendFailures
{
    /**
     * @var array<string, class-string<Throwable>>
     */
    private array $failures = [];

    /**
     * @var array<string, true>
     */
    private array $tried = [];

    /**
     * @var array<string, true>
     */
    private array $unreachable = [];

    /**
     * @var array<string, int>
     */
    private array $limitedSeconds = [];

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

    // Noted as the call goes out, because the accounts free after the call can include one whose cooldown ended during it and that was never sent to.
    public function tried(Account $account): void
    {
        $this->tried[$account->key()] = true;
    }

    public function wasTried(Account $account): bool
    {
        return isset($this->tried[$account->key()]);
    }

    // True when every account the call was sent to failed with a limit, so none of them failed for a reason a retry would not fix.
    public function isEveryTriedLimited(): bool
    {
        return array_all(array_keys($this->tried), fn ($key) => is_a($this->failures[$key] ?? '', RateLimitExceededException::class, true));
    }

    // A number only, never the provider's text: the wait the limit named for this account after the minimum, the default and the cap were applied, kept by the call because the stores can fail to save it.
    public function limitedFor(Account $account, int $seconds): void
    {
        $this->limitedSeconds[$account->key()] = $seconds;
    }

    // Null instead of zero when no account of the call recorded a limit's wait, because a wait of zero would read as a free account.
    public function shortestLimitedWait(): ?int
    {
        return [] === $this->limitedSeconds ? null : min($this->limitedSeconds);
    }

    // Kept apart from the account errors, because a transport error cools no account down.
    public function unreachable(Account $account): void
    {
        $this->unreachable[$account->key()] = true;
    }

    public function isEveryTriedUnreachable(): bool
    {
        return [] !== $this->tried && [] === array_diff_key($this->tried, $this->unreachable);
    }
}
