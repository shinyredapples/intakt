<?php

declare(strict_types=1);

use Symfony\AI\Platform\Bridge\ClaudeCode\TokenUsageExtractor;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

// The usage of the CLI's final result message from the trial of 2026-11-13, because a cache write costs 1.25 times the input price and a cache read a tenth of it.
$result = new InMemoryRawResult([
    'type' => 'result',
    'subtype' => 'success',
    'result' => 'Done.',
    'usage' => [
        'input_tokens' => 6,
        'cache_creation_input_tokens' => 3771,
        'cache_read_input_tokens' => 10078,
        'output_tokens' => 412,
    ],
]);

$usage = new TokenUsageExtractor()->extract($result);
if (!$usage instanceof TokenUsageInterface) {
    echo 'The extractor returned no usage', PHP_EOL;

    exit(1);
}

echo 'cached: ', var_export($usage->getCachedTokens(), true), PHP_EOL;
echo 'cache creation: ', var_export($usage->getCacheCreationTokens(), true), PHP_EOL;
echo 'cache read: ', var_export($usage->getCacheReadTokens(), true), PHP_EOL;

if (3771 !== $usage->getCacheCreationTokens() || 10078 !== $usage->getCacheReadTokens()) {
    echo 'The cache creation and cache read tokens the CLI reported apart are lost', PHP_EOL;

    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
