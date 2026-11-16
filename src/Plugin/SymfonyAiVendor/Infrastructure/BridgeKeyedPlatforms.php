<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\KeyKind;
use Intakt\Core\Ai\Contract\ProviderKey;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class BridgeKeyedPlatforms implements KeyedPlatforms
{
    // Hugging Face serves its hosted models through an OpenAI compatible endpoint, so the generic bridge reaches it.
    private const string HUGGING_FACE_URL = 'https://router.huggingface.co';

    public function __construct(
        private HttpClientInterface $http,
    ) {
    }

    public function platformFor(ProviderKey $key): PlatformInterface
    {
        $secret = $key->secret();

        return match ($key->kind()) {
            KeyKind::Anthropic => AnthropicFactory::createPlatform($secret, $this->http),
            KeyKind::OpenAi => OpenAiFactory::createPlatform($secret, $this->http),
            KeyKind::HuggingFace => GenericFactory::createPlatform(
                self::HUGGING_FACE_URL,
                $secret,
                $this->http,
                supportsEmbeddings: false,
                name: KeyKind::HuggingFace->value,
            ),
        };
    }
}
