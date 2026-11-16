Opened as [symfony/ai#2963](https://github.com/symfony/ai/pull/2963) on 2026-11-16, with this text and the commit of `fix.patch`.

Commit:

```
[Platform][ClaudeCode] Report cache creation and cache read tokens apart

The Claude Code CLI reports cache_creation_input_tokens and
cache_read_input_tokens apart, as the Anthropic API does, but the
extractor only added them up into cachedTokens and left
cacheCreationTokens and cacheReadTokens null. A consumer that prices
cache writes and reads differently could not tell them apart. Both are
now set, as the Anthropic bridge sets them; cachedTokens keeps the sum.
```

---

[Platform][ClaudeCode] Report cache creation and cache read tokens apart

| Q             | A
| ------------- | ---
| Bug fix?      | yes
| New feature?  | no
| Docs?         | no
| Issues        | -
| License       | MIT

The Claude Code CLI's `result` message reports `cache_creation_input_tokens` and `cache_read_input_tokens` apart, as the Anthropic API does. The Claude Code `TokenUsageExtractor` added them into `cachedTokens` and left `cacheCreationTokens` and `cacheReadTokens` null, although `TokenUsage` has both fields and the Anthropic bridge's extractor sets them. A 5-minute cache write costs 1.25 times the input price and a cache read a tenth of it on most models, so a consumer pricing the call could not tell which applies: for a call with 3771 tokens written and 10078 read, pricing the 13849 cached tokens at the input price would give 2.4 times their cost at Claude Opus 5 prices.

The change sets `cacheCreationTokens` and `cacheReadTokens` the way the Anthropic bridge does. `cachedTokens` keeps the sum, and stays null when the CLI reports neither. The one difference: when both cache fields are an explicit `null`, `cachedTokens` now stays null instead of 0, as in the Anthropic bridge (the CLI does not send one).

Tests, in `TokenUsageExtractorTest`:

- `testExtractWithCachedTokens` also asserts the two split figures.
- `testExtractWithOnlyCacheReadTokens`: a usage with only `cache_read_input_tokens` gives that figure as read and as cached, and no creation figure.

Out of scope: the streamed result of this bridge yields no `TokenUsage` at all, and the Codex bridge maps `cached_input_tokens` to `cachedTokens`, as the OpenResponses bridge does for OpenAI's `cached_tokens`.
