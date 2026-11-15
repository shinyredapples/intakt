<?php

declare(strict_types=1);

use Symfony\AI\Platform\Bridge\ClaudeCode\ModelClient;
use Symfony\AI\Platform\Bridge\ClaudeCode\RawProcessResult;
use Symfony\AI\Platform\Bridge\ClaudeCode\ResultConverter;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\Process\Process;

// A plain JSON schema, the form the platform passes on without checking the model's capabilities.
$schema = ['type' => 'object', 'properties' => ['verdict' => ['type' => 'string']], 'required' => ['verdict'], 'additionalProperties' => false];
$responseFormat = ['type' => 'json_schema', 'json_schema' => ['name' => 'verdict', 'schema' => $schema, 'strict' => true]];

$failed = false;

$warnings = [];
set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$command = new ModelClient(PHP_BINARY)->buildCommand('Judge the change.', ['model' => 'sonnet', PlatformSubscriber::RESPONSE_FORMAT => $responseFormat]);
restore_error_handler();

echo 'command: ', implode(' ', array_slice($command, 1)), PHP_EOL;
foreach ($warnings as $warning) {
    echo 'warning: ', $warning, PHP_EOL;
}

$schemaFlag = array_search('--json-schema', $command, true);
if (false === $schemaFlag || ($command[$schemaFlag + 1] ?? null) !== json_encode($schema) || in_array('--response-format', $command, true)) {
    // Claude Code 2.1.302 answers this command with "error: unknown option '--response-format'" and exit code 1.
    echo 'The response format reaches the CLI as --response-format flags, not as its --json-schema option', PHP_EOL;
    $failed = true;
}

// The result event of a run started with --json-schema: the answer held to the schema is in structured_output, the text beside it is prose.
$run = '{"type":"system","subtype":"init","model":"sonnet","tools":["StructuredOutput"]}'.PHP_EOL
    .'{"type":"result","subtype":"success","is_error":false,"result":"I reviewed the change and returned the verdict.","structured_output":{"verdict":"approve"}}'.PHP_EOL;

$process = new Process(['printf', '%s', $run]);
$process->start();
$content = new ResultConverter()->convert(new RawProcessResult($process))->getContent();

echo 'converted: ', json_encode($content), PHP_EOL;

if (!is_string($content) || ['verdict' => 'approve'] !== json_decode($content, true)) {
    echo 'The structured output of the run is dropped and its prose is returned instead', PHP_EOL;
    $failed = true;
}

if ($failed) {
    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
