<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Core\Review\Contract\SampleProjectData;
use Intakt\Plugin\Vendor\Contract\KeyCheck;
use Intakt\Plugin\Vendor\Contract\KeyCheckOutcome;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Exception\AuthenticationException;
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
        private LoggerInterface $logger,
        private PromptAssembly $assembly = new PromptAssembly(),
        private SampleProjectData $sample = new SampleProjectData(),
    ) {
    }

    public function check(ProviderKey $key): KeyCheckOutcome
    {
        $provider = $this->providers->descriptor($key->provider());
        if (!$provider instanceof SymfonyAiProvider) {
            return KeyCheckOutcome::NotChecked;
        }

        $model = $this->checkModelOf($provider);

        try {
            $provider->platform($key->secret(), $model, $this->http)
                ->invoke($model, new MessageBag($this->assembly->notice(self::PROMPT)), [PlatformOptions::MAX_OUTPUT_TOKENS => $provider->answerTokensAtLeast()])
                ->asText();
        } catch (AuthenticationException) {
            return KeyCheckOutcome::Refused;
            // Boundary: logs any other provider failure and answers not checked, because a check that cannot answer must not refuse a key.
        } catch (Throwable $failure) {
            // Only the class, because a provider's text can echo the request headers that carry the key, and a key not yet kept is no held credential the log redacts.
            $this->logger->warning('A provider key could not be checked.', ['provider' => $key->provider(), 'exception_class' => $failure::class]);

            return KeyCheckOutcome::NotChecked;
        }

        return KeyCheckOutcome::Accepted;
    }

    /**
     * @return non-empty-string
     */
    private function checkModelOf(SymfonyAiProvider $provider): string
    {
        return $provider->checkModel() ?? $this->lightModelOn($provider->tierProvider()) ?? $this->sampleLightModelOn($provider->tierProvider());
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

    /**
     * @return non-empty-string
     */
    // The light model a fresh project gets, because a fresh install has no project until a key is kept, and the check must still ask a model.
    private function sampleLightModelOn(Provider $provider): string
    {
        return $this->sample->model($provider, Tier::Light);
    }
}
