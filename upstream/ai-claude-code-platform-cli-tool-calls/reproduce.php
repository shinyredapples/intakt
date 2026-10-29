<?php

declare(strict_types=1);

use Symfony\AI\Platform\Bridge\ClaudeCode\RawProcessResult;
use Symfony\AI\Platform\Bridge\ClaudeCode\ResultConverter;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\Process\Process;

// A CLI run in which the CLI called its own Read tool, got the answer and then gave its final result.
$run = '{"type":"assistant","message":{"role":"assistant","content":[{"type":"tool_use","id":"toolu_01","name":"Read","input":{"file_path":"README.md"}}]}}'.PHP_EOL
    .'{"type":"user","message":{"role":"user","content":[{"type":"tool_result","tool_use_id":"toolu_01","content":"# Readme"}]}}'.PHP_EOL
    .'{"type":"result","subtype":"success","is_error":false,"result":"The readme has a title."}'.PHP_EOL;

$process = new Process(['printf', '%s', $run]);
$process->start();

$result = new ResultConverter()->convert(new RawProcessResult($process));
echo $result::class, PHP_EOL;

if (!$result instanceof TextResult) {
    echo 'The tool call the CLI already ran is handed to the caller to run again', PHP_EOL;

    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
