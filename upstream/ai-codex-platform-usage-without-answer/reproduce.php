<?php

declare(strict_types=1);

use Symfony\AI\Platform\Bridge\Codex\RawProcessResult;
use Symfony\AI\Platform\Bridge\Codex\TokenUsageExtractor;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\Process\Process;

// A completed turn with reasoning but no agent message, because its usage was spent all the same.
$run = '{"type":"turn.started"}'.PHP_EOL
    .'{"type":"item.completed","item":{"id":"item_0","type":"reasoning","text":"Checking."}}'.PHP_EOL
    .'{"type":"turn.completed","usage":{"input_tokens":23,"cached_input_tokens":4,"output_tokens":7}}'.PHP_EOL;

$process = new Process(['printf', '%s', $run]);
$process->start();

$usage = new TokenUsageExtractor()->extract(new RawProcessResult($process));
echo 'usage: ', $usage instanceof TokenUsageInterface ? $usage->getPromptTokens().' prompt tokens' : 'none', PHP_EOL;

if (!$usage instanceof TokenUsageInterface) {
    echo 'The usage of a turn without an agent message is lost', PHP_EOL;

    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
