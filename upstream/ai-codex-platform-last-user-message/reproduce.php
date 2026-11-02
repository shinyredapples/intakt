<?php

declare(strict_types=1);

use Symfony\AI\Platform\Bridge\Codex\Codex;
use Symfony\AI\Platform\Bridge\Codex\Contract\CodexContract;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

// Two user messages in a row, because a caller that sends its context in parts loses all but the last.
$bag = new MessageBag(
    Message::forSystem('You review changes.'),
    Message::ofUser('The task: FIRST'),
    Message::ofUser('The diff: SECOND'),
);

$payload = CodexContract::create()->createRequestPayload(new Codex('gpt-5.3-codex'), $bag);
$prompt = is_array($payload) ? (string) ($payload['prompt'] ?? '') : $payload;
echo 'prompt: "', $prompt, '"', PHP_EOL;

if (!str_contains($prompt, 'FIRST') || !str_contains($prompt, 'SECOND')) {
    echo 'Every user message but the last is dropped from the prompt', PHP_EOL;

    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
