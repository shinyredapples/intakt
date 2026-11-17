<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use SensitiveParameter;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\Factory;
use Symfony\AI\Platform\ModelCatalog\InMemoryModelCatalog;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Uri\Rfc3986\Uri;

final readonly class HuggingFaceDescriptor implements SymfonyAiProvider
{
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

    // No /v1, because the generic bridge appends /v1/chat/completions.
    public function baseUrl(): Uri
    {
        return new Uri('https://router.huggingface.co');
    }

    // Its router models are mapped on the OpenAI compatible endpoint, which the image points at the router when HF_TOKEN is set.
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
