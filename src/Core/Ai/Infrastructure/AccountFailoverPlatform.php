<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryKeyRejected;
use Intakt\Core\Ai\Contract\Provider;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Failover\FailoverPlatform;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Throwable;

// The accounts of one provider in the owner's order; excluded from the container because config/packages/ai.php defines one per provider with its accounts.
#[Exclude]
final readonly class AccountFailoverPlatform implements PlatformInterface
{
    /**
     * @param non-empty-array<string, PlatformInterface> $platforms
     */
    public function __construct(
        private Provider $provider,
        private array $platforms,
        private AccountCooldowns $cooldowns,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $failures = new SendFailures();
        $free = $this->freeAccounts($failures);
        if ([] === $free) {
            throw $this->outOfAccounts($failures);
        }

        $this->recordSwitch($free);

        try {
            $answer = $this->failover($free)->invoke($model, $input, $options);
        } catch (RuntimeException $everyAccountFailed) {
            throw $this->typed($everyAccountFailed, $failures);
        }

        return $this->typedWhenRead($answer, $failures, $options);
    }

    #[Override]
    public function getModelCatalog(): ModelCatalogInterface
    {
        return array_first($this->platforms)->getModelCatalog();
    }

    /**
     * @return array<string, AccountPlatform>
     */
    private function freeAccounts(SendFailures $failures): array
    {
        $free = [];

        foreach ($this->platforms as $name => $platform) {
            $account = new Account($this->provider, $name);
            if (!$this->cooldowns->isCoolingDown($account)) {
                $free[$name] = new AccountPlatform($account, $platform, $this->cooldowns, $this->logger, $failures);
            }
        }

        return $free;
    }

    /**
     * @param non-empty-array<string, AccountPlatform> $free
     */
    private function recordSwitch(array $free): void
    {
        $first = array_key_first($this->platforms);
        $next = array_key_first($free);
        if ($first === $next) {
            return;
        }

        $this->logger->info('Account {first} of {provider} is cooling down, so the call goes to account {next}.', ['provider' => $this->provider->value, 'first' => $first, 'next' => $next]);
    }

    // FailoverPlatform's own error names no cause, so a provider whose accounts all cooled down during the call says so like one that was out before it.
    private function typed(RuntimeException $everyAccountFailed, SendFailures $failures): Throwable
    {
        if ([] !== $this->freeAccounts($failures)) {
            return $everyAccountFailed;
        }

        return $this->outOfAccounts($failures);
    }

    // When every account of the provider rejected its key, in this call or in an earlier one it still cools down from, there is nothing to wait for, so the rejection itself is thrown.
    private function outOfAccounts(SendFailures $failures): Throwable
    {
        return $this->rejectionOfEveryAccount($failures) ?? new EveryAccountCoolingDown($this->provider, $this->cooldowns->secondsUntilAnAccountIsFree($this->provider));
    }

    // Judged across calls through the shared cooldowns, because two accounts whose rejections land a moment apart would otherwise each be free in turn and the call would wait forever (one worker's rejection beside another's).
    private function rejectionOfEveryAccount(SendFailures $failures): ?AuthenticationException
    {
        $rejection = null;

        foreach (array_keys($this->platforms) as $name) {
            $account = new Account($this->provider, $name);
            $rejected = $failures->rejectionOf($account);
            if (!$rejected instanceof AuthenticationException && !$this->cooldowns->isRejected($account)) {
                return null;
            }

            $rejection = $rejected ?? $rejection;
        }

        // The last rejection of this call, because on the read path that is the one the reader just met and expects back; none when every rejection came before this call.
        return $rejection ?? new EveryKeyRejected($this->provider);
    }

    // A key rejected when the answer is read follows the same rule as one rejected as the call is sent, because an account cooling down from a limit beside it is free again after its wait.
    /**
     * @param array<string, mixed> $options
     */
    private function typedWhenRead(DeferredResult $answer, SendFailures $failures, array $options): DeferredResult
    {
        $converter = new TypedRejectionConverter($answer, fn (AuthenticationException $rejected): Throwable => $this->typed($rejected, $failures));
        $typed = new DeferredResult($converter, $answer->getRawResult(), $options);
        $typed->getMetadata()->set($answer->getMetadata()->all());

        return $typed;
    }

    // Built per call over the free accounts only, because FailoverPlatform keys its limiter by platform class, which every account of a provider shares, and calls a failed platform again on the next call (upstream/ai-failover-platform-retries-a-failed-platform).
    /**
     * @param non-empty-array<string, AccountPlatform> $free
     */
    private function failover(array $free): FailoverPlatform
    {
        $neverLimits = new RateLimiterFactory(['id' => 'account-failover', 'policy' => 'no_limit'], new InMemoryStorage());

        // A silent logger, because FailoverPlatform logs the provider's message, which can echo a key; AccountPlatform logs each failure by class instead.
        return new FailoverPlatform(array_values($free), $neverLimits, $this->clock, new NullLogger());
    }
}
