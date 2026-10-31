<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\Provider;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\Failover\FailoverPlatform;
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
     * @param non-empty-array<string, PlatformInterface> $platforms the provider's accounts by name, in the owner's order
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
        $free = $this->freeAccounts();
        if ([] === $free) {
            throw new EveryAccountCoolingDown($this->provider, $this->cooldowns->secondsUntilAnAccountIsFree($this->provider));
        }

        $this->recordSwitch($free);

        try {
            return $this->failover($free)->invoke($model, $input, $options);
        } catch (RuntimeException $everyAccountFailed) {
            throw $this->typed($everyAccountFailed);
        }
    }

    #[Override]
    public function getModelCatalog(): ModelCatalogInterface
    {
        return array_first($this->platforms)->getModelCatalog();
    }

    /**
     * @return array<string, AccountPlatform>
     */
    private function freeAccounts(): array
    {
        $free = [];

        foreach ($this->platforms as $name => $platform) {
            $account = new Account($this->provider, $name);
            if (!$this->cooldowns->isCoolingDown($account)) {
                $free[$name] = new AccountPlatform($account, $platform, $this->cooldowns);
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

    // FailoverPlatform's own error names no cause, so a provider whose accounts all cooled down during the call says so with its wait.
    private function typed(RuntimeException $everyAccountFailed): Throwable
    {
        if ([] !== $this->freeAccounts()) {
            return $everyAccountFailed;
        }

        return new EveryAccountCoolingDown($this->provider, $this->cooldowns->secondsUntilAnAccountIsFree($this->provider));
    }

    // Built per call over the free accounts only, because FailoverPlatform keys its limiter by platform class, which every account of a provider shares, and calls a failed platform again on the next call (upstream/ai-failover-platform-retries-a-failed-platform).
    /**
     * @param non-empty-array<string, AccountPlatform> $free
     */
    private function failover(array $free): FailoverPlatform
    {
        $neverLimits = new RateLimiterFactory(['id' => 'account-failover', 'policy' => 'no_limit'], new InMemoryStorage());

        return new FailoverPlatform(array_values($free), $neverLimits, $this->clock, $this->logger);
    }
}
