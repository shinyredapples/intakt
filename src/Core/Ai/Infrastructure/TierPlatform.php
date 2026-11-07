<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\AiServices;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryKeyRejected;
use Intakt\Core\Ai\Contract\EveryProviderOut;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ProviderOutage;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Contract\TierResolver;
use NoDiscard;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\PlatformOptions;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ServiceProviderInterface;

final readonly class TierPlatform implements TierInvoker
{
    /**
     * @param ServiceProviderInterface<PlatformInterface> $platforms
     */
    public function __construct(
        private TierResolver $resolver,
        #[Autowire(service: AiServices::PROVIDER_PLATFORMS)]
        private ServiceProviderInterface $platforms,
        private AccountCooldowns $cooldowns,
        private LoggerInterface $logger,
    ) {
    }

    // Decided by the configuration and the cooldowns alone, never by a failed call, because a request that fails on purpose would otherwise let its sender pick the reviewer.
    #[Override]
    public function answering(string $project, Tier $tier): ResolvedModel
    {
        if (!$this->resolver->hasSubstitutes($project, $tier)) {
            return $this->ownWithoutSubstitutes($project, $tier);
        }

        $own = $this->resolver->configured($project, $tier);
        $outages = [];

        foreach ([$own, ...$this->resolver->substitutes($project, $tier)] as $model) {
            $outages[$model->provider->value] ??= $this->outageOf($model->provider);
            if (!$outages[$model->provider->value] instanceof ProviderOutage) {
                $this->recordSwitch($own, $model);

                return $model;
            }
        }

        /** @var non-empty-list<ProviderOutage> $out */
        $out = array_values(array_filter($outages));

        throw new EveryProviderOut($out);
    }

    // Invoked on the provider's own bundle platform because its catalog-based model router and model_catalog already know the configured model.
    #[Override]
    #[NoDiscard('the answer is only in the result')]
    public function invoke(ResolvedModel $model, TierRequest $request): DeferredResult
    {
        return $this->platforms->get($model->provider->value)->invoke($model->model, $request->input, $this->withOutputLimit($model, $request->options));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function withOutputLimit(ResolvedModel $model, array $options): array
    {
        if (null === $model->maxOutput || !$model->provider->limitsOutput()) {
            return $options;
        }

        return $options + [PlatformOptions::MAX_OUTPUT_TOKENS => $model->maxOutput];
    }

    // A tier without substitutes keeps the wait of its one provider, so a reviewer on it waits as before instead of ending blocked, but not for keys that were all rejected, because no wait brings them back.
    private function ownWithoutSubstitutes(string $project, Tier $tier): ResolvedModel
    {
        $own = $this->resolver->resolve($project, $tier);
        if ($this->cooldowns->isEveryAccountRejected($own->provider)) {
            throw new EveryKeyRejected($own->provider);
        }

        $wait = $this->cooldowns->secondsUntilAnAccountIsFree($own->provider);
        if (0 < $wait) {
            throw new EveryAccountCoolingDown($own->provider, $wait);
        }

        return $own;
    }

    // One read of the cooldowns decides both whether the provider answers and the reason it does not, so a cooldown that ends in between never gives a wait of 0 s. Ends blocked even when every substitute is disabled, because the owner named a way around the outage.
    private function outageOf(Provider $provider): ?ProviderOutage
    {
        if ($this->resolver->isDisabled($provider)) {
            return ProviderOutage::disabled($provider);
        }

        if ($this->cooldowns->isEveryAccountRejected($provider)) {
            return ProviderOutage::keysRejected($provider);
        }

        $wait = $this->cooldowns->secondsUntilAnAccountIsFree($provider);

        return 0 < $wait ? ProviderOutage::coolingDown($provider, $wait) : null;
    }

    private function recordSwitch(ResolvedModel $own, ResolvedModel $answering): void
    {
        if ($answering === $own) {
            return;
        }

        $this->logger->info('Provider {own} is out, so the review goes to the substitute {substitute}.', ['own' => $own->provider->value, 'substitute' => $answering->provider->value]);
    }
}
