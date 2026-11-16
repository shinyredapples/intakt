<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\KeyKind;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Plugin\Vendor\Contract\KeyCheck;
use Intakt\Plugin\Vendor\Contract\KeyCheckOutcome;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\PlatformOptions;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final readonly class SymfonyAiKeyCheck implements KeyCheck
{
    // A constant, so no outside text ever reaches a model through this call.
    private const string PROMPT = 'Answer with the single word: ok';

    // Hugging Face serves its hosted models through an OpenAI compatible endpoint, so the generic bridge reaches it.
    private const string HUGGING_FACE_URL = 'https://router.huggingface.co';

    public function __construct(
        private HttpClientInterface $http,
    ) {
    }

    public function check(ProviderKey $key): KeyCheckOutcome
    {
        $kind = $key->kind();

        try {
            $this->platformFor($key)
                ->invoke($this->cheapestModel($kind), new MessageBag(Message::ofUser(self::PROMPT)), [PlatformOptions::MAX_OUTPUT_TOKENS => $this->answerTokens($kind)])
                ->asText();
        } catch (AuthenticationException) {
            return KeyCheckOutcome::Refused;
        } catch (Throwable) {
            // Dropped without its message or chain, because a provider's text can echo the request headers that carry the key.
            return KeyCheckOutcome::NotChecked;
        }

        return KeyCheckOutcome::Accepted;
    }

    private function platformFor(ProviderKey $key): PlatformInterface
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

    /**
     * @return non-empty-string
     */
    private function cheapestModel(KeyKind $kind): string
    {
        return match ($kind) {
            KeyKind::Anthropic => 'claude-haiku-5-5',
            KeyKind::OpenAi => 'gpt-4.1-nano',
            KeyKind::HuggingFace => 'meta-llama/Llama-3.2-1B-Instruct',
        };
    }

    /**
     * @return positive-int
     */
    private function answerTokens(KeyKind $kind): int
    {
        return match ($kind) {
            // OpenAI's Responses API refuses a max_output_tokens below 16.
            KeyKind::OpenAi => 16,
            KeyKind::Anthropic, KeyKind::HuggingFace => 1,
        };
    }
}
