<?php

declare(strict_types=1);

use Symfony\AI\Platform\Bridge\ClaudeCode\RawProcessResult;
use Symfony\AI\Platform\Bridge\ClaudeCode\ResultConverter;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\Component\Process\Process;

// The first lines of a CLI run that stops before its final `type=result` event.
$cutOff = '{"type":"system","subtype":"init"}'.PHP_EOL
    .'{"type":"stream_event","event":{"type":"message_start","message":{"role":"assistant"}}}'.PHP_EOL;

$endings = ['exit 0' => 'the CLI exits 0', 'exit 143' => 'the CLI exits 143', 'kill -TERM $$' => 'the CLI is killed'];
$failed = false;

foreach ($endings as $ending => $case) {
    $process = new Process(['sh', '-c', 'printf "%s" "$0"; '.$ending, $cutOff]);
    $process->start();

    try {
        new ResultConverter()->convert(new RawProcessResult($process));
        echo $case, ': no exception', PHP_EOL;
        $failed = true;
    } catch (IncompleteStreamException) {
        echo $case, ': IncompleteStreamException', PHP_EOL;
    } catch (Throwable $other) {
        echo $case, ': ', $other::class, ' "', $other->getMessage(), '"', PHP_EOL;
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
