<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;

// Prompts are put together only here, so Intakt's own text arrives as literals (the phpstan gate refuses a runtime string for a literal-string) and untrusted text only fenced: docs/design/05-architecture/14-threat-model.md#third-party-text
final readonly class PromptAssembly
{
    /**
     * @param literal-string $instructions
     */
    public function system(string $instructions): SystemMessage
    {
        return Message::forSystem($instructions);
    }

    // D2 exception (#2518): the role prompt is trusted owner configuration, the `prompt` key of a panel member in the panel configuration, so it enters verbatim; it is taken only from the conversation that carries the panel's role, never as a plain string, so no other runtime text can reach the system message.
    /**
     * @param literal-string $instructions
     */
    public function systemWithRole(string $instructions, ReviewerConversation $conversation): SystemMessage
    {
        return Message::forSystem($instructions."\n\n".$conversation->rolePrompt);
    }

    /**
     * @param literal-string $notice
     */
    public function notice(string $notice): UserMessage
    {
        return Message::ofUser($notice);
    }

    /**
     * @param literal-string $lead
     */
    public function user(string $lead, FencedText $body): UserMessage
    {
        return Message::ofUser($this->userText($lead, $body));
    }

    // Public because a capped message is measured before it is sent.
    /**
     * @param literal-string $lead
     */
    public function userText(string $lead, FencedText $body): string
    {
        return $lead."\n\n".$body->rendered();
    }

    // A string, not messages, because the builder agent takes its prompt as one text; its parts still admit only literals and fences: docs/design/05-architecture/14-threat-model.md#builder
    public function document(PromptPart ...$parts): string
    {
        return implode("\n\n", array_map(static fn (PromptPart $part): string => $part->rendered(), $parts));
    }

    public function toolAnswer(FencedText $answer): string
    {
        return $answer->rendered();
    }
}
