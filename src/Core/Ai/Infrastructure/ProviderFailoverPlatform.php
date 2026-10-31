<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryProviderOut;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ProviderWait;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\TierRequest;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Failover\FailoverPlatform;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Service\ServiceProviderInterface;

// The tier's own provider first, then its configured substitutes in order; a provider counts as out only when every one of its accounts is, because AccountFailoverPlatform tries them first.
final readonly class ProviderFailoverPlatform
{
    /**
     * @param ServiceProviderInterface<PlatformInterface> $platforms
     */
    public function __construct(
        #[Autowire(service: TierPlatform::PLATFORMS)]
        private ServiceProviderInterface $platforms,
        private AccountCooldowns $cooldowns,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<ResolvedModel> $substitutes
     *
     * @throws EveryAccountCoolingDown
     * @throws EveryProviderOut
     */
    public function invoke(ResolvedModel $own, array $substitutes, TierRequest $request): DeferredResult
    {
        $trail = new FailoverTrail();
        $candidates = $this->freeCandidates([$own, ...$substitutes], $trail);
        if ([] === $candidates) {
            throw $this->outage($own, $substitutes);
        }

        try {
            $result = $this->failover($candidates)->invoke($own->model, $request->input, $request->options);
        } catch (RuntimeException $everyCandidateFailed) {
            throw $trail->hardFailure() ?? $this->outage($own, $substitutes, $trail);
        }

        $this->recordSwitch($own, $trail->answeredBy(), $result);

        return $result;
    }

    /**
     * @param list<ResolvedModel> $models
     *
     * @return list<ProviderCandidate>
     */
    private function freeCandidates(array $models, FailoverTrail $trail): array
    {
        $free = [];

        foreach ($models as $model) {
            if (0 === $this->cooldowns->secondsUntilAnAccountIsFree($model->provider)) {
                $free[] = new ProviderCandidate($model, $this->platforms->get($model->provider->value), $trail);
            }
        }

        return $free;
    }

    /**
     * @param list<ResolvedModel> $substitutes
     */
    // A tier without substitutes keeps the wait of its one provider, so a reviewer on it waits as before instead of ending blocked.
    private function outage(ResolvedModel $own, array $substitutes, ?FailoverTrail $trail = null): EveryAccountCoolingDown|EveryProviderOut
    {
        if ([] === $substitutes) {
            return $trail?->firstOut() ?? new EveryAccountCoolingDown($own->provider, $this->cooldowns->secondsUntilAnAccountIsFree($own->provider));
        }

        $waits = [];

        foreach ([$own, ...$substitutes] as $model) {
            $waits[$model->provider->value] ??= new ProviderWait($model->provider, $this->cooldowns->secondsUntilAnAccountIsFree($model->provider));
        }

        return new EveryProviderOut(array_values($waits));
    }

    // The mark is on the result, so the vendor learns of the switch from what the platform recorded and not from FailoverPlatform, which records nothing.
    private function recordSwitch(ResolvedModel $own, ?Provider $answeredBy, DeferredResult $result): void
    {
        if (!$answeredBy instanceof Provider || $answeredBy === $own->provider) {
            return;
        }

        $result->getMetadata()->add(Provider::SUBSTITUTE_METADATA, $answeredBy->value);
        $this->logger->info('Provider {own} is out, so the call goes to the substitute {substitute}.', ['own' => $own->provider->value, 'substitute' => $answeredBy->value]);
    }

    // Built per call: it keys its limiter by platform class and calls a failed platform again on the next call (upstream/ai-failover-platform-retries-a-failed-platform); a silent logger, because it logs the provider's message, which can echo a key.
    /**
     * @param non-empty-list<ProviderCandidate> $candidates
     */
    private function failover(array $candidates): FailoverPlatform
    {
        $neverLimits = new RateLimiterFactory(['id' => 'provider-failover', 'policy' => 'no_limit'], new InMemoryStorage());

        return new FailoverPlatform($candidates, $neverLimits, $this->clock, new NullLogger());
    }
}
