<?php

declare(strict_types=1);

use Symfony\AI\Platform\Bridge\Codex\Codex;
use Symfony\AI\Platform\Bridge\Codex\Contract\CodexContract;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

function promptOf(MessageBag $bag): string
{
    $payload = CodexContract::create()->createRequestPayload(new Codex('gpt-5.3-codex'), $bag);

    return is_array($payload) ? (string) ($payload['prompt'] ?? '') : $payload;
}

// Two user messages in a row, because a caller that sends its context in parts loses all but the last.
$twoMessages = promptOf(new MessageBag(
    Message::forSystem('You review changes.'),
    Message::ofUser('The task: FIRST'),
    Message::ofUser('The diff: SECOND'),
));
echo 'two user messages, prompt: "', $twoMessages, '"', PHP_EOL;

// One user message with two text blocks, because the normalizer stops at the first block too (`break 2`).
$twoBlocks = promptOf(new MessageBag(
    Message::forSystem('You review changes.'),
    Message::ofUser('The task: FIRST', 'The diff: SECOND'),
));
echo 'one user message with two text blocks, prompt: "', $twoBlocks, '"', PHP_EOL;

$failed = false;

foreach (['two user messages' => $twoMessages, 'two text blocks' => $twoBlocks] as $case => $prompt) {
    if (!str_contains($prompt, 'FIRST') || !str_contains($prompt, 'SECOND')) {
        echo $case, ': part of the user text is dropped from the prompt', PHP_EOL;
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
