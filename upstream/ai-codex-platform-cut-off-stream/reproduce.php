<?php

declare(strict_types=1);

use Symfony\AI\Platform\Bridge\Codex\RawProcessResult;
use Symfony\AI\Platform\Bridge\Codex\ResultConverter;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\Component\Process\Process;

// No `turn.completed` event at the end, because only that event tells a finished turn from a cut-off one.
$cutOff = '{"type":"turn.started"}'.PHP_EOL
    .'{"type":"item.completed","item":{"id":"item_0","type":"agent_message","text":"{\"verdict\":\"approve\"}"}}'.PHP_EOL;

$process = new Process(['printf', '%s', $cutOff]);
$process->start();

try {
    $result = new ResultConverter()->convert(new RawProcessResult($process));
    echo $result::class, ': "', $result->getContent(), '"', PHP_EOL;
    echo 'A turn that never completed passes for a complete answer', PHP_EOL;

    exit(1);
} catch (IncompleteStreamException) {
    echo 'IncompleteStreamException', PHP_EOL;
}

echo 'REPRODUCTION PASSES', PHP_EOL;
