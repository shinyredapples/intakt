<?php

declare(strict_types=1);

use Symfony\AI\Platform\Bridge\Codex\RawProcessResult;
use Symfony\AI\Platform\Bridge\Codex\ResultConverter;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\Component\Process\Process;

// No `turn.completed` event at the end, because only that event tells a finished turn from a cut-off one.
// `printf` exits 0 like `codex exec` does when its event channel closes before the turn ends.
function cutOffTurn(): RawProcessResult
{
    $process = new Process(['printf', '%s', '{"type":"turn.started"}'.PHP_EOL
        .'{"type":"item.completed","item":{"id":"item_0","type":"agent_message","text":"{\"verdict\":\"approve\"}"}}'.PHP_EOL]);
    $process->start();

    return new RawProcessResult($process);
}

$failed = false;

try {
    $result = new ResultConverter()->convert(cutOffTurn());
    echo 'not streamed: ', $result::class, ': "', $result->getContent(), '"', PHP_EOL;
    echo 'A turn that never completed passes for a complete answer', PHP_EOL;
    $failed = true;
} catch (IncompleteStreamException) {
    echo 'not streamed: IncompleteStreamException', PHP_EOL;
}

try {
    $text = '';
    foreach (new ResultConverter()->convert(cutOffTurn(), ['stream' => true])->getContent() as $delta) {
        $text .= $delta instanceof TextDelta ? $delta->getText() : '';
    }
    echo 'streamed: "', $text, '"', PHP_EOL;
    echo 'A streamed turn that never completed ends without an error', PHP_EOL;
    $failed = true;
} catch (IncompleteStreamException) {
    echo 'streamed: IncompleteStreamException', PHP_EOL;
}

if ($failed) {
    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
