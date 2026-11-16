<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\KeyKind;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Plugin\Vendor\Contract\KeyCheck;
use Intakt\Plugin\Vendor\Contract\KeyCheckOutcome;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformOptions;
use Throwable;

final readonly class SymfonyAiKeyCheck implements KeyCheck
{
    // A constant, so no outside text ever reaches a model through this call.
    private const string PROMPT = 'Answer with the single word: ok';
    private const int ANSWER_TOKENS = 1;

    public function __construct(
        private KeyedPlatforms $platforms,
    ) {
    }

    public function check(ProviderKey $key): KeyCheckOutcome
    {
        try {
            $this->platforms->platformFor($key)
                ->invoke($this->cheapestModel($key->kind()), new MessageBag(Message::ofUser(self::PROMPT)), [PlatformOptions::MAX_OUTPUT_TOKENS => self::ANSWER_TOKENS])
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
}
