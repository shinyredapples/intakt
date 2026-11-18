<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Review\Contract\SampleProjectData;
use SensitiveParameter;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\Factory;
use Symfony\AI\Platform\ModelCatalog\InMemoryModelCatalog;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Uri\Rfc3986\Uri;

final readonly class HuggingFaceDescriptor implements SymfonyAiProvider
{
    public function __construct(
        private ?Uri $baseUrl = null,
    ) {
    }

    public function id(): string
    {
        return 'huggingface';
    }

    public function label(): string
    {
        return 'Hugging Face';
    }

    public function variables(): array
    {
        return ['HF_TOKEN'];
    }

    public function keyPattern(): string
    {
        return '/^hf_[A-Za-z0-9]{8,}$/D';
    }

    // The sample project's router URL unless one is given, so the key check calls the router the image points openai_compatible at.
    public function baseUrl(): Uri
    {
        return $this->baseUrl ?? new SampleProjectData()->routerUrl();
    }

    // The OpenAI compatible endpoint, because the image points it at the router when HF_TOKEN is set, so the router models are mapped there.
    public function tierProvider(): Provider
    {
        return Provider::OpenAiCompatible;
    }

    public function checkModel(): ?string
    {
        return null;
    }

    public function answerTokensAtLeast(): int
    {
        return 1;
    }

    // Hugging Face serves its hosted models through an OpenAI compatible endpoint, so the generic bridge reaches it.
    public function platform(#[SensitiveParameter] string $secret, string $model, HttpClientInterface $http): PlatformInterface
    {
        return Factory::createPlatform(
            $this->baseUrl()->toString(),
            $secret,
            $http,
            new InMemoryModelCatalog([$model => ['class' => CompletionsModel::class, 'capabilities' => self::CHECK_CAPABILITIES]]),
            supportsEmbeddings: false,
            name: $this->id(),
        );
    }
}
