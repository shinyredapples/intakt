<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\EnvironmentVariables;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiProvider;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Uri\Rfc3986\Uri;

// A listed provider whose descriptor names its check model, so a test shows the override without a model id in a shipped descriptor.
final readonly class NamedCheckModel implements SymfonyAiProvider
{
    /**
     * @param non-empty-string $model
     */
    public function __construct(
        private SymfonyAiProvider $provider,
        private string $model,
    ) {
    }

    public function id(): string
    {
        return $this->provider->id();
    }

    public function label(): string
    {
        return $this->provider->label();
    }

    public function variables(): EnvironmentVariables
    {
        return $this->provider->variables();
    }

    public function keyPattern(): string
    {
        return $this->provider->keyPattern();
    }

    public function baseUrl(): Uri
    {
        return $this->provider->baseUrl();
    }

    public function tierProvider(): Provider
    {
        return $this->provider->tierProvider();
    }

    public function checkModel(): string
    {
        return $this->model;
    }

    public function answerTokensAtLeast(): int
    {
        return $this->provider->answerTokensAtLeast();
    }

    public function platform(string $secret, string $model, HttpClientInterface $http): PlatformInterface
    {
        return $this->provider->platform($secret, $model, $http);
    }
}
