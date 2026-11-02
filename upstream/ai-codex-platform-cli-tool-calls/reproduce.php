<?php

declare(strict_types=1);

use Symfony\AI\Platform\Bridge\Codex\RawProcessResult;
use Symfony\AI\Platform\Bridge\Codex\ResultConverter;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\Process\Process;

// A tool call sits mid-stream because the CLI runs its tools itself, so the bridge must not hand it back.
$run = '{"type":"turn.started"}'.PHP_EOL
    .'{"type":"item.started","item":{"id":"item_0","type":"mcp_tool_call","server":"files","tool":"read_file","arguments":{"path":"README.md"},"status":"in_progress"}}'.PHP_EOL
    .'{"type":"item.completed","item":{"id":"item_0","type":"mcp_tool_call","server":"files","tool":"read_file","arguments":{"path":"README.md"},"status":"completed"}}'.PHP_EOL
    .'{"type":"item.completed","item":{"id":"item_1","type":"agent_message","text":"The readme has a title."}}'.PHP_EOL
    .'{"type":"turn.completed","usage":{"input_tokens":900,"cached_input_tokens":0,"output_tokens":40}}'.PHP_EOL;

$process = new Process(['printf', '%s', $run]);
$process->start();

$result = new ResultConverter()->convert(new RawProcessResult($process));
echo $result::class, PHP_EOL;

if (!$result instanceof TextResult) {
    echo 'The tool call the CLI already ran is handed to the caller to run again', PHP_EOL;

    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
