<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Plugin\Vendor\Contract\KeyCheck;
use Intakt\Plugin\Vendor\Contract\KeyCheckOutcome;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformOptions;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final readonly class SymfonyAiKeyCheck implements KeyCheck
{
    // A constant, so no outside text ever reaches a model through this call.
    private const string PROMPT = 'Answer with the single word: ok';

    public function __construct(
        private HttpClientInterface $http,
        private SymfonyAiProviderCatalog $providers,
        private TierResolver $tiers,
    ) {
    }

    public function check(ProviderKey $key): KeyCheckOutcome
    {
        $provider = $this->providers->descriptor($key->provider());
        $model = $provider instanceof SymfonyAiProvider ? $this->checkModelOf($provider) : null;

        if (!$provider instanceof SymfonyAiProvider || null === $model) {
            return KeyCheckOutcome::NotChecked;
        }

        try {
            $provider->platform($key->secret(), $model, $this->http)
                ->invoke($model, new MessageBag(Message::ofUser(self::PROMPT)), [PlatformOptions::MAX_OUTPUT_TOKENS => $provider->answerTokensAtLeast()])
                ->asText();
        } catch (AuthenticationException) {
            return KeyCheckOutcome::Refused;
        } catch (Throwable) {
            // Dropped without its message or chain, because a provider's text can echo the request headers that carry the key.
            return KeyCheckOutcome::NotChecked;
        }

        return KeyCheckOutcome::Accepted;
    }

    /**
     * @return non-empty-string|null
     */
    private function checkModelOf(SymfonyAiProvider $provider): ?string
    {
        return $provider->checkModel() ?? $this->lightModelOn($provider->tierProvider());
    }

    /**
     * @return non-empty-string|null
     */
    // The first project's light tier on that provider, because it is the cheapest model the owner already maps there.
    private function lightModelOn(Provider $provider): ?string
    {
        foreach ($this->tiers->projects() as $project) {
            try {
                $light = $this->tiers->configured($project, Tier::Light);
            } catch (TierResolutionFailure) {
                continue;
            }

            if ($light->provider === $provider) {
                return $light->model;
            }
        }

        return null;
    }
}
