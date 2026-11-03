<?php

declare(strict_types=1);

use Symfony\AI\Platform\Bridge\ClaudeCode\RawProcessResult;
use Symfony\AI\Platform\Bridge\ClaudeCode\ResultConverter;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\Process\Process;

function cliRun(string $output): RawProcessResult
{
    $process = new Process(['printf', '%s', $output]);
    $process->start();

    return new RawProcessResult($process);
}

// A tool call sits mid-stream because the CLI runs its tools itself, so the bridge must not hand it back.
$run = '{"type":"assistant","message":{"role":"assistant","content":[{"type":"tool_use","id":"toolu_01","name":"Read","input":{"file_path":"README.md"}}]}}'.PHP_EOL
    .'{"type":"user","message":{"role":"user","content":[{"type":"tool_result","tool_use_id":"toolu_01","content":"# Readme"}]}}'.PHP_EOL
    .'{"type":"result","subtype":"success","is_error":false,"result":"The readme has a title."}'.PHP_EOL;

// The same run as `--output-format stream-json --include-partial-messages` prints it, because the stream path has the same bug.
$streamedRun = '{"type":"stream_event","event":{"type":"message_start"}}'.PHP_EOL
    .'{"type":"stream_event","event":{"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_01","name":"Read"}}}'.PHP_EOL
    .'{"type":"stream_event","event":{"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"{\"file_path\":\"README.md\"}"}}}'.PHP_EOL
    .'{"type":"stream_event","event":{"type":"content_block_stop","index":0}}'.PHP_EOL
    .'{"type":"stream_event","event":{"type":"message_stop"}}'.PHP_EOL
    .'{"type":"user","message":{"role":"user","content":[{"type":"tool_result","tool_use_id":"toolu_01","content":"# Readme"}]}}'.PHP_EOL
    .'{"type":"stream_event","event":{"type":"message_start"}}'.PHP_EOL
    .'{"type":"stream_event","event":{"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"The readme has a title."}}}'.PHP_EOL
    .'{"type":"stream_event","event":{"type":"message_stop"}}'.PHP_EOL
    .'{"type":"result","subtype":"success","is_error":false,"result":"The readme has a title."}'.PHP_EOL;

$failed = false;

$result = new ResultConverter()->convert(cliRun($run));
echo 'not streamed: ', $result::class, PHP_EOL;

if (!$result instanceof TextResult) {
    echo 'The tool call the CLI already ran is handed to the caller to run again', PHP_EOL;
    $failed = true;
}

$deltas = [];
foreach (new ResultConverter()->convert(cliRun($streamedRun), ['stream' => true])->getContent() as $delta) {
    $deltas[] = $delta instanceof TextDelta ? 'TextDelta "'.$delta->getText().'"' : $delta::class;
}
echo 'streamed: ', implode(', ', $deltas), PHP_EOL;

if ([] !== array_filter($deltas, static fn (string $delta): bool => ToolCallComplete::class === $delta)) {
    echo 'The stream completes a tool call the CLI already ran, so an agent drops the text after it', PHP_EOL;
    $failed = true;
}

if ($failed) {
    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
