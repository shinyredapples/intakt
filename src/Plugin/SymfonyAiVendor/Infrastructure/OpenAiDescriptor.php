<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use SensitiveParameter;
use Symfony\AI\Platform\Bridge\OpenResponses\Factory;
use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\ModelCatalog\InMemoryModelCatalog;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Uri\Rfc3986\Uri;

final readonly class OpenAiDescriptor implements SymfonyAiProvider
{
    public function __construct(
        private Uri $baseUrl = new Uri('https://api.openai.com'),
    ) {
    }

    public function id(): string
    {
        return Provider::OpenAi->value;
    }

    public function label(): string
    {
        return 'OpenAI';
    }

    // Read from the enum, because its accounts still build their variables from it until its match arms move into descriptors.
    public function variables(): array
    {
        return Provider::OpenAi->variables();
    }

    // The Anthropic prefix is excluded, so a cut-off Anthropic key is refused instead of going to OpenAI.
    public function keyPattern(): string
    {
        return '/^sk-(?!ant-)[A-Za-z0-9_-]{8,}$/D';
    }

    public function baseUrl(): Uri
    {
        return $this->baseUrl;
    }

    public function tierProvider(): Provider
    {
        return Provider::OpenAi;
    }

    public function checkModel(): ?string
    {
        return null;
    }

    // 16 because OpenAI's Responses API refuses a smaller max_output_tokens with a 400.
    public function answerTokensAtLeast(): int
    {
        return 16;
    }

    // The Open Responses bridge the OpenAI bridge builds on, because the OpenAI bridge takes a region instead of a base URL.
    public function platform(#[SensitiveParameter] string $secret, string $model, HttpClientInterface $http): PlatformInterface
    {
        return Factory::createPlatform(
            $this->baseUrl()->toString(),
            $secret,
            $http,
            new InMemoryModelCatalog([$model => ['class' => ResponsesModel::class, 'capabilities' => self::CHECK_CAPABILITIES]]),
            name: $this->id(),
        );
    }
}
