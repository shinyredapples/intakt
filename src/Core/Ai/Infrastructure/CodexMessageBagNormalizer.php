<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Override;
use Symfony\AI\Platform\Bridge\Codex\Codex;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Model;

// The bridge's normalizer keeps only the last user message as the prompt, yet a reviewer gets its task, diff, briefing and open findings as separate ones (upstream/ai-codex-platform-last-user-message).
final class CodexMessageBagNormalizer extends ModelContractNormalizer
{
    private const string SEPARATOR = PHP_EOL.PHP_EOL;

    /**
     * @return array{prompt: string, system_prompt?: string}
     */
    #[Override]
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $bag = $data instanceof MessageBag ? $data : throw new InvalidArgumentException('Only a message bag becomes a Codex prompt.');
        $prompt = ['prompt' => $this->userTextOf($bag)];
        $system = $bag->getSystemMessage();

        return $system instanceof SystemMessage ? [...$prompt, 'system_prompt' => (string) $system->getContent()] : $prompt;
    }

    #[Override]
    protected function supportedDataClass(): string
    {
        return MessageBag::class;
    }

    #[Override]
    protected function supportsModel(Model $model): bool
    {
        return $model instanceof Codex;
    }

    private function userTextOf(MessageBag $bag): string
    {
        $users = array_filter($bag->getMessages(), static fn (object $message): bool => $message instanceof UserMessage);

        return implode(self::SEPARATOR, array_map(static fn (UserMessage $message): string => (string) $message->asText(), $users));
    }
}
