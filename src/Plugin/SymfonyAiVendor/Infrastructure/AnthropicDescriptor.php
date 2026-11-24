<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\EnvironmentVariables;
use Intakt\Core\Ai\Contract\Provider;
use SensitiveParameter;
use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\AI\Platform\ModelCatalog\InMemoryModelCatalog;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Uri\Rfc3986\Uri;

final readonly class AnthropicDescriptor implements SymfonyAiProvider
{
    public function __construct(
        private Uri $baseUrl = new Uri('https://api.anthropic.com'),
    ) {
    }

    public function id(): string
    {
        return Provider::Anthropic->value;
    }

    public function label(): string
    {
        return 'Anthropic';
    }

    // Read from the enum, because its accounts still build their variables from it until its match arms move into descriptors.
    public function variables(): EnvironmentVariables
    {
        return Provider::Anthropic->variables();
    }

    public function keyPattern(): string
    {
        return '/^sk-ant-[A-Za-z0-9_-]{8,}$/D';
    }

    public function baseUrl(): Uri
    {
        return $this->baseUrl;
    }

    public function tierProvider(): Provider
    {
        return Provider::Anthropic;
    }

    public function checkModel(): ?string
    {
        return null;
    }

    public function answerTokensAtLeast(): int
    {
        return 1;
    }

    public function platform(#[SensitiveParameter] string $secret, string $model, HttpClientInterface $http): PlatformInterface
    {
        $models = new InMemoryModelCatalog([$model => ['class' => Claude::class, 'capabilities' => self::CHECK_CAPABILITIES]]);

        return Factory::createPlatform($secret, $http, $models, baseUrl: $this->baseUrl()->toString());
    }
}
