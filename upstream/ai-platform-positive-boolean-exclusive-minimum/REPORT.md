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
