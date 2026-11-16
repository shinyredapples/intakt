# [Platform][ClaudeCode] The extractor adds cache creation and cache read tokens up and drops the split

Versions: `symfony/ai-claude-code-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, PHP 8.5.

## What happens

The Claude Code CLI's final `result` message reports `cache_creation_input_tokens` and `cache_read_input_tokens` apart, as the Anthropic API does. `Symfony\AI\Platform\Bridge\ClaudeCode\TokenUsageExtractor::extract()` adds them into `cachedTokens` and leaves `cacheCreationTokens` and `cacheReadTokens` null, although `TokenUsage` has both fields and the Anthropic bridge's `TokenUsageExtractor` sets them. A consumer that prices cache writes and reads apart cannot tell them apart. `reproduce.php` feeds the usage of one call from the trial of 2026-11-13 (cache creation 3771, cache read 10078) to the extractor and prints:

```
cached: 13849
cache creation: NULL
cache read: NULL
The cache creation and cache read tokens the CLI reported apart are lost
```

## Expected

`cacheCreationTokens` 3771 and `cacheReadTokens` 10078, with `cachedTokens` keeping the sum 13849, as the Anthropic bridge reports it.

## Effect in Intakt

Intakt's ledger keeps the vendor's `cached`, `cache_creation` and `cache_read` as reported (`src/Plugin/SymfonyAiVendor/Infrastructure/UsageLedger.php`), so the row reads `cached` 13849 with the two split fields null. `TokenUsageRow::figures()` shows that as one `cached` figure, and `ConfiguredPrices` prices unsplit cached tokens at the input price, because which of the two prices applies is not known (documented in `docs/architecture/vendor.md` and `docs/architecture/round-cost.md`). At a price of 5 input, 0.5 cache read and 6.25 cache write per million, the 13849 cached tokens cost 0.0692 instead of 0.0286 (2.4 times), which is the excess seen against the CLI's own cost figure. With the split reported, Intakt prices them right without any change on its side.

The Codex lead is not a bug. The Codex CLI reports only `cached_input_tokens`, which the Codex bridge maps to `cachedTokens`, as the OpenAI (OpenResponses) bridge does for OpenAI's `cached_tokens`. For providers that count cached tokens within the prompt, `TokenUsageRow::figures()` shows `cached` as the `cacheRead` figure on purpose (OpenAI's cached tokens are reads), so the console's `cached` 0 with `cache read` set is Intakt's documented mapping, not a lost split.

## Upstream status

- **Checked:** 2026-11-15 against `symfony/ai` `main` 1220483ebd24 (the default branch) and v1.0.0-BETA1 (the newest release); duplicate search below.
- **Pinned versions:** still fails. `reproduce.php` at v1.0.0-BETA1 prints the output above and exits 1.
- **Development branch:** still fails. The same script with the autoloader of a scratch clone of `main` 1220483ebd24 (`src/platform/src/Bridge/ClaudeCode` installed and `./link`ed to the clone's platform) prints the same output and exits 1; with `fix.patch` applied it prints `REPRODUCTION PASSES`.
- **Commits since the pinned version:** `git log v1.0.0-BETA1..origin/main -- src/platform/src/Bridge/ClaudeCode/TokenUsageExtractor.php src/platform/src/Bridge/Codex/TokenUsageExtractor.php`: none. The six commits on the two bridges since BETA1 (tool calls, tool definitions, last user message, `turn.completed`) do not touch token usage; no release after BETA1 exists, so there are no release notes to read.
- **Existing issue or pull request:** none found. Candidates, none the same bug: #2709 (open pull request, Anthropic streaming cache tokens of server tool turns), #2178 (closed pull request, Anthropic streaming double count), #2658 and #2659 (closed issue and pull request, OpenResponses cache-write tokens), #2275 (closed pull request, Generic cache tokens), #2760 (open pull request, token usage aggregation), #2544 (open issue, Claude Code conversation history). Open pull requests touching `src/platform/src/Bridge/ClaudeCode/`: #2713 changes only `RawProcessResult.php`.
- **Searches** (`symfony/ai` issues and pull requests, open and closed): `cache_creation_input_tokens`, `ClaudeCode cache`, `cacheReadTokens`, `cache read tokens`, `Codex cached`, `cacheCreationTokens`, `is:pr is:open ClaudeCode`, `is:pr is:open Codex`, `is:pr TokenUsageExtractor`, `is:pr is:open token usage`, `is:issue Claude Code usage`.
- **Pull requests and commits:** none fixes it (checked 2026-11-15 at `symfony/ai` `main` 999d5fbeea73, after the 1220483ebd24 check above). Pull requests searched with `is:pr ClaudeCode cache tokens` (#2875, merged, tool calls; #1826, merged, the Codex bridge) and `is:pr TokenUsageExtractor ClaudeCode` (#1826); the open and closed ones in the line above stand. Commits: `git log 1220483ebd24..origin/main -- src/platform/src/Bridge/ClaudeCode/ src/platform/src/Bridge/Codex/TokenUsageExtractor.php` lists none (the bridge commits since BETA1 all precede 1220483ebd24: 1fc422e4 and 32056f8e for #2875, d23c5adf and a1c443fe for #2889, 98a1dd01 for #2891, 642cd818 for #2876, none about token usage); `fix.patch` still touches only `TokenUsageExtractor.php` and its test.
- **Form and target:** a fix pull request against `main`, from the pull request template (`.github/PULL_REQUEST_TEMPLATE.md`: bug fixes need no issue, no `CHANGELOG.md` change for a bug fix).
- **Decision:** ready to file as a fix pull request.
- **Fix pull request:** fix pull request ready. One bridge, the expected behavior follows the Anthropic bridge, `cachedTokens` keeps its value so nothing breaks, and the package suite tests it. Prepared on `symfony/ai` `main` 1220483ebd24 as `fix.patch` and `PR.md`; the package's own suite passes with it (`vendor/bin/phpunit` in `src/platform/src/Bridge/ClaudeCode`: `OK (89 tests, 255 assertions)`), and its two new assertions fail on the old extractor (`Failed asserting that null is identical to 9864.`, `... to 14162.`).
- **When a release fixes it:** once a later `intakt:upstream:run` finds the reproduction passing on a Symfony AI release, this section records that release.
