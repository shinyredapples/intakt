# [Platform] `#[Positive]` is described with a boolean `exclusiveMinimum`

Draft, not posted. Versions: `symfony/ai-platform` v1.0.0-BETA1, `symfony/validator` v8.1.9, PHP 8.5.12.

## What happens

`ValidatorConstraintsDescriber` describes `Positive` (and the other exclusive lower bounds) as `{"minimum": 0, "exclusiveMinimum": true}`. That is the JSON Schema draft 4 form. Most structured output providers and current validators speak draft 2020-12, where `exclusiveMinimum` must be a number, so the schema is invalid there and a draft 2020-12 validator refuses it.

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
- **Pull request search (D12, 2026-11-03, `main` 17d320e7beee):** open with the prefix `[Platform]` (40: #2873, #2872, #2871, #2870, #2862, #2861, #2807, #2762, #2761, #2760, #2756, #2727, #2725, #2723, #2722, #2721, #2718, #2713, #2709, #2708, #2651, #2582, #2577, #2576, #2570, #2448, #2411, #2388, #2357, #2078, #2077, #2010, #1975, #1864, #1808, #1762, #1751, #1747, #1452, #1446; none about constraints or exclusive bounds); open touching `src/platform/src/Contract/JsonSchema/`: #2448 (`Factory` and describers) and #2411 (`Attribute/Schema.php`), neither changes `ValidatorConstraintsDescriber`; merged since BETA1 on that path: be546619 only.
- **Earlier assessment (replaced by the Status line below):** `issue only`. The fix changes the schema every `#[Positive]`, `#[Negative]`, `#[LessThan]` and `#[GreaterThan]` property sends to every provider, which is a backward-compatibility question: `ValidatorConstraintsDescriberTest` pins the boolean form (`FactoryTest` pins the numeric form), and the Anthropic bridge strips the key. Open pull requests (checked 2026-11-03 against `main` 17d320e7beee): none changes `ValidatorConstraintsDescriber`; the one commit merged since BETA1 on the JSON schema code (be546619, `null` in the enum of nullable properties) does not touch the exclusive bounds.
- **Owner review (2026-11-03, `main` 17d320e7):** only `ValidatorConstraintsDescriberTest` pins the boolean form, not `FactoryTest`, and "providers speak draft 2020-12" is now "most providers". The owner session prepares the fix pull request.
- **Recheck (2026-11-06, `main` 6747a33):** still fails: `ValidatorConstraintsDescriber` still writes `"exclusiveMinimum": true`, and `ValidatorConstraintsDescriberTest` still pins that form.
- **Fix pull request:** `fix pull request ready`: `fix.patch` (`git format-patch` against `main` 6747a33) and `PR.md`. The patch writes the numeric draft 2020-12 form for the exclusive lower and upper bound in `ValidatorConstraintsDescriber` and updates `ValidatorConstraintsDescriberTest` (`Positive`, `Numeric range`, a new `Negative` case with a `#[Negative]` property in `ValidatorConstraintsFixture`). It applies cleanly to 6747a33; the three cases fail without the source change and pass with it, and the package suite is green (1180 tests, 1 skipped). Apply it with `git am --keep-non-patch fix.patch`, which keeps the `[Platform]` prefix in the subject.
- **Status:** `fix pull request ready`: `fix.patch`, `PR.md`.
