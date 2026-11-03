# [Platform] `#[Positive]` is described with a boolean `exclusiveMinimum`

Draft, not posted. Versions: `symfony/ai-platform` v1.0.0-BETA1, `symfony/validator` v8.1.9, PHP 8.5.12.

## What happens

`ValidatorConstraintsDescriber` describes `Positive` (and the other exclusive lower bounds) as `{"minimum": 0, "exclusiveMinimum": true}`. That is the JSON Schema draft 4 form. Structured output providers and current validators speak draft 2020-12, where `exclusiveMinimum` must be a number, so the schema is invalid there and a draft 2020-12 validator refuses it.

`reproduce.php` builds the response format for a class with one `#[Positive] int` property and prints:

```
{"type":"integer","minimum":0,"exclusiveMinimum":true}
exclusiveMinimum is not a number
```

## Expected

`{"type":"integer","exclusiveMinimum":0}`, the draft 2020-12 form (the same applies to `exclusiveMaximum` for `Negative`, `LessThan` and `GreaterThan`).

## Effect in Intakt

`src/Core/Review/Contract/Finding.php` uses `#[GreaterThanOrEqual(1)]` on `line` instead of `#[Positive]`, which describes as `"minimum": 1`.

## Upstream status

Checked on 2026-11-02.

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/ai-*` v1.0.0-BETA1: `still fails  ai-platform-positive-boolean-exclusive-minimum`.
- **Development branch:** still fails. The same run in a scratch copy with every `symfony/ai-*` package at `main` (`symfony/ai-platform` 17c452fa350f): `still fails  ai-platform-positive-boolean-exclusive-minimum`, still `"exclusiveMinimum":true`.
- **Existing issue or pull request:** none found. Candidates in `symfony/ai`, none the same bug: #1722 (closed pull request that added the Validator metadata describer), #2527 (open pull request, configurable validation groups for structured output), #2552 and #2765 (open, reuse `mcp/sdk` `#[Schema]` constraints for agent tools).
- **Searches** (`symfony/ai` issues and pull requests, open and closed): `exclusiveMinimum`, `ValidatorConstraintsDescriber`, `Positive constraint schema`.
- **Decision:** `ready to file`.
- **Fix pull request:** `issue only`. The fix changes the schema every `#[Positive]`, `#[Negative]`, `#[LessThan]` and `#[GreaterThan]` property sends to every provider, which is a backward-compatibility question: `ValidatorConstraintsDescriberTest` and `FactoryTest` pin the boolean form, and the Anthropic bridge strips the key. Open pull requests (checked 2026-11-03 against `main` 17d320e7beee): none changes `ValidatorConstraintsDescriber`; the one commit merged since BETA1 on the JSON schema code (be546619, `null` in the enum of nullable properties) does not touch the exclusive bounds.
