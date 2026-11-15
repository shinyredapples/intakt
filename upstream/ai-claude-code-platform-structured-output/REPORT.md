# [Claude Code] A response format never reaches the CLI's `--json-schema` option

Versions: `symfony/ai-claude-code-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, `symfony/process` v8.1.10, PHP 8.5.12, Claude Code CLI 2.1.302.

## What happens

The Claude Code CLI has its own structured-output option: `--json-schema <schema>` holds the answer to a JSON schema and returns it in the `structured_output` field of the final `result` event, beside the prose in `result`. The bridge uses neither side of it:

- `ModelClient::buildCommand()` turns every option into a flag named after its key, so the platform's `response_format` option becomes `--response-format`. The value is an array, so each of its items becomes one flag: `--response-format json_schema --response-format Array`, with an `Array to string conversion` warning for the nested `json_schema` part. Claude Code 2.1.302 refuses the command with `error: unknown option '--response-format'` and exit code 1.
- `ResultConverter` returns the `result` text of the final event and ignores `structured_output`, so a run started with `--json-schema` (for example with a `json_schema` option, which the bridge does pass as `--json-schema`) still returns prose.
- `ModelCatalog` declares no `Capability::OUTPUT_STRUCTURED`, so a class or object response format is refused with `MissingModelSupportException` before the CLI starts. A plain JSON-schema array is not checked against the capabilities by `StructuredOutput\PlatformSubscriber::processInput()`, so it reaches the bridge and the broken command above.

The bridge's `README.md` links the CLI reference and documents no structured output; `CHANGELOG.md` has no entry for it.

`reproduce.php` builds the command for a plain JSON-schema response format and converts a recorded-shape run that carries `structured_output`, and prints:

```
command: --output-format stream-json --verbose --include-partial-messages --model sonnet --response-format json_schema --response-format Array -p Judge the change.
warning: Array to string conversion
The response format reaches the CLI as --response-format flags, not as its --json-schema option
converted: "I reviewed the change and returned the verdict."
The structured output of the run is dropped and its prose is returned instead
```

## Expected

The response format's schema passed as `--json-schema` with the schema JSON, and the result event's `structured_output` returned as the answer (JSON-encoded, so `PlatformSubscriber::processResult()` deserializes it as for other bridges); with that, the catalog can declare `OUTPUT_STRUCTURED`. At the least, a response format the bridge cannot map is refused before the CLI starts instead of becoming an unknown flag.

## Effect in Intakt

The trial of 2026-11-13 that led here (prose around one fenced `json` block) was Intakt's own doing: `ConfinedClaudeCodeClient` dropped the response format and appended the schema to the system prompt, and `ProjectModelCatalog` granted the capability. Intakt now passes the schema as `--json-schema` itself and `FinishedProcessResult` puts `structured_output` in place of `result` (both in `src/Core/Ai/Infrastructure/`); they stay until a Symfony AI release maps the response format.

## Upstream status

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/ai-*` v1.0.0-BETA1: `still fails  ai-claude-code-platform-structured-output`.
- **Development branch:** still fails, with the same output. Run in a scratch copy of `vendor/` with `src/platform` and `src/platform/src/Bridge/ClaudeCode` of `symfony/ai` `main` 1220483ebd24 swapped in. The last commit on those paths, 1fc422e4 (#2875), does not touch the response format.
- **Commits since BETA1** (`git log v1.0.0-BETA1..origin/main -- src/platform/src/Bridge/ClaudeCode/`): 32056f8e and its merge 1fc422e4 (#2875, CLI tool calls), 98a1dd01 (user messages after the last assistant message), 642cd818 (rejects tool definitions); none adds structured output. No `CHANGELOG.md` entry for it on `main`.
- **Existing issue or pull request:** none found. Candidates in `symfony/ai`, none the same: #2465 (closed pull request, widens `asObject()` for plain JSON schemas, CLI error reason in `RawProcessResult`), #2545 (open RFC, persistent CLI processes), #2544 (open issue, multi-turn through session resume), #2714 (open issue, common invocation options through `PlatformOptions`), #2713 (open pull request, JSON Lines on any platform), #2527 and #2570 (open pull requests, structured output schema options in the platform).
- **Pull request search (2026-11-15, `main` 1220483ebd24):** open with the prefix `[Platform][ClaudeCode]`: #2713; open with `ClaudeCode` anywhere: #2713 and #1747 (exception taxonomy); merged since BETA1 on the bridge's path: #2875 and the two commits above.
- **Checked:** 2026-11-15 against `symfony/ai` `main` 1220483ebd24 and the newest release v1.0.0-BETA1 (f620d0fc6790); duplicate search in `symfony/ai` issues and pull requests, open and closed, with the terms `ClaudeCode structured`, `"json-schema" Claude`, `"Claude Code" response_format`, `ClaudeCode OUTPUT_STRUCTURED`, `"structured_output"`, `ClaudeCode json_schema`, `is:issue ClaudeCode`, `is:pr is:open ClaudeCode`. Preferred form: a fix pull request against `main` (`CONTRIBUTING.md`: no maintenance branches, every pull request targets `main`); this one goes as an issue, see below.
- **Fix pull request:** `issue only`. The fix spans `ModelClient`, `ResultConverter` (both paths, streamed and not) and `ModelCatalog`, and whether the bridge should map the response format or refuse it is the maintainers' choice, while #2714 may change how invocation options reach the bridges.
- **Decision:** `ready to file`. The issue text is `ISSUE.md`.
