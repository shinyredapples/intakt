Repository: symfony/ai. Template: Bug Report (label `Bug`). Not posted: the owner approves this text first.

Title: [Platform][ClaudeCode] A response format becomes `--response-format Array` instead of the CLI's `--json-schema`

### Description

The Claude Code CLI has a structured-output option: `--json-schema <schema>` holds the answer to the schema and returns it in the `structured_output` field of the final `result` event. The bridge does not use it:

- `ModelClient::buildCommand()` turns `response_format` into flags named after the key: `--response-format json_schema --response-format Array`, with an `Array to string conversion` warning. The CLI refuses the command: `error: unknown option '--response-format'`, exit code 1.
- `ResultConverter` returns the `result` text and ignores `structured_output`, so even a run started with `json_schema` (passed as `--json-schema`) returns prose.
- `ModelCatalog` declares no `OUTPUT_STRUCTURED`, so a class response format is refused with `MissingModelSupportException`, but a plain JSON-schema array is not checked by `StructuredOutput\PlatformSubscriber` and reaches the broken command above.

### Versions

`symfony/ai-claude-code-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, PHP 8.5.12, Claude Code CLI 2.1.302. Still the same on `main` (1220483).

### How to reproduce

```php
use Symfony\AI\Platform\Bridge\ClaudeCode\ModelClient;
use Symfony\AI\Platform\Bridge\ClaudeCode\RawProcessResult;
use Symfony\AI\Platform\Bridge\ClaudeCode\ResultConverter;
use Symfony\Component\Process\Process;

$schema = ['type' => 'object', 'properties' => ['verdict' => ['type' => 'string']], 'required' => ['verdict']];
$format = ['type' => 'json_schema', 'json_schema' => ['name' => 'verdict', 'schema' => $schema, 'strict' => true]];

echo implode(' ', (new ModelClient(PHP_BINARY))->buildCommand('Judge the change.', ['model' => 'sonnet', 'response_format' => $format])), PHP_EOL;

$process = new Process(['printf', '%s', '{"type":"result","subtype":"success","is_error":false,"result":"I returned the verdict.","structured_output":{"verdict":"approve"}}'.PHP_EOL]);
$process->start();
echo (new ResultConverter())->convert(new RawProcessResult($process))->getContent(), PHP_EOL;
```

### Actual behavior

```
Warning: Array to string conversion
... --model sonnet --response-format json_schema --response-format Array -p Judge the change.
I returned the verdict.
```

### Expected behavior

`--json-schema '{"type":"object",...}'` in the command, and `{"verdict":"approve"}` (JSON-encoded) as the answer, so `PlatformSubscriber` deserializes it as for other bridges; the catalog can then declare `OUTPUT_STRUCTURED`. At the least, a response format the bridge cannot map should be refused before the CLI starts.

### Possible solution

`ModelClient` maps `response_format` to `--json-schema` with `json_encode($format['json_schema']['schema'])`; `RawProcessResult::getData()` keeps `structured_output` of the result event, and `ResultConverter` returns it JSON-encoded when present (the streamed path can yield it once the `result` event arrives); `ModelCatalog` adds `Capability::OUTPUT_STRUCTURED`.

### Additional context

Related, not the same: #2714 (common invocation options through `PlatformOptions`), #2465 (plain JSON-schema results in `asObject()`).
