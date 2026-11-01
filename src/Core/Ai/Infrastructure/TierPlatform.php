<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryProviderOut;
use Intakt\Core\Ai\Contract\ProviderWait;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Contract\TierResolver;
use NoDiscard;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ServiceProviderInterface;

final readonly class TierPlatform implements TierInvoker
{
    public const string PLATFORMS = 'intakt.ai.provider_platforms';

    /**
     * @param ServiceProviderInterface<PlatformInterface> $platforms
     */
    public function __construct(
        private TierResolver $resolver,
        #[Autowire(service: self::PLATFORMS)]
        private ServiceProviderInterface $platforms,
        private AccountCooldowns $cooldowns,
        private LoggerInterface $logger,
    ) {
    }

    // Decided by the cooldowns alone, never by a failed call, because a request that fails on purpose would otherwise let its sender pick the reviewer.
    #[Override]
    public function answering(string $project, Tier $tier): ResolvedModel
    {
        $own = $this->resolver->resolve($project, $tier);
        $substitutes = $this->resolver->substitutes($project, $tier);

        $answering = array_find([$own, ...$substitutes], $this->hasAFreeAccount(...)) ?? throw $this->outage($project, $tier, [$own, ...$substitutes]);
        $this->recordSwitch($own, $answering);

        return $answering;
    }

    // Invoked on the provider's own bundle platform because its catalog-based model router and model_catalog already know the configured model.
    #[Override]
    #[NoDiscard('the answer is only in the result')]
    public function invoke(ResolvedModel $model, TierRequest $request): DeferredResult
    {
        return $this->platforms->get($model->provider->value)->invoke($model->model, $request->input, $request->options);
    }

    private function hasAFreeAccount(ResolvedModel $model): bool
    {
        return 0 === $this->cooldowns->secondsUntilAnAccountIsFree($model->provider);
    }

    /**
     * @param non-empty-list<ResolvedModel> $usable
     */
    // A tier without substitutes keeps the wait of its one provider, so a reviewer on it waits as before instead of ending blocked; one whose substitutes are all disabled still ends blocked, because the owner named a way around the outage.
    private function outage(string $project, Tier $tier, array $usable): EveryAccountCoolingDown|EveryProviderOut
    {
        $own = $usable[0];

        if (!$this->resolver->hasSubstitutes($project, $tier)) {
            return new EveryAccountCoolingDown($own->provider, $this->cooldowns->secondsUntilAnAccountIsFree($own->provider));
        }

        $waits = [];

        foreach ($usable as $model) {
            $waits[$model->provider->value] ??= new ProviderWait($model->provider, $this->cooldowns->secondsUntilAnAccountIsFree($model->provider));
        }

        return new EveryProviderOut(array_values($waits));
    }

    private function recordSwitch(ResolvedModel $own, ResolvedModel $answering): void
    {
        if ($answering === $own) {
            return;
        }

        $this->logger->info('Provider {own} is out, so the review goes to the substitute {substitute}.', ['own' => $own->provider->value, 'substitute' => $answering->provider->value]);
    }
}
