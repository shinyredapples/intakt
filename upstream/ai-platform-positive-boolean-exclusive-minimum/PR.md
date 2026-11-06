Commit:

```
[Platform] Describe exclusive bounds with the numeric exclusiveMinimum and exclusiveMaximum

ValidatorConstraintsDescriber described GreaterThan and Positive as minimum plus "exclusiveMinimum": true, and LessThan and Negative as maximum plus "exclusiveMaximum": true. That boolean form is JSON Schema draft 4; in draft 2020-12, which most structured output providers and current validators use, both keywords take the bound itself, so a draft 2020-12 validator refuses the schema. The bound is now written as "exclusiveMinimum": <value> or "exclusiveMaximum": <value>, the form Factory already produces for #[Schema].
```

---

[Platform] Describe exclusive bounds with the numeric exclusiveMinimum and exclusiveMaximum

| Q             | A
| ------------- | ---
| Bug fix?      | yes
| New feature?  | no
| Docs?         | no
| Issues        | -
| License       | MIT

`ValidatorConstraintsDescriber` describes `#[Positive]` and `#[GreaterThan]` as `{"minimum": 0, "exclusiveMinimum": true}`, and `#[Negative]` and `#[LessThan]` as `{"maximum": 0, "exclusiveMaximum": true}`. That boolean form is JSON Schema draft 4. In draft 2020-12, which most structured output providers and current validators use, `exclusiveMinimum` and `exclusiveMaximum` are numbers, so a draft 2020-12 validator refuses the schema. A structured output class with a `#[Positive] int` property therefore sends an invalid schema.

The change writes the exclusive bound as the keyword's value: `#[Positive]` becomes `{"exclusiveMinimum": 0}`, `#[LessThan(10)]` becomes `{"exclusiveMaximum": 10}`. This is the form `Factory` already produces for `#[Schema(exclusiveMinimum: ...)]`. Inclusive bounds (`GreaterThanOrEqual`, `LessThanOrEqual`, `PositiveOrZero`, `NegativeOrZero`, `Range`) are unchanged. The Anthropic bridge strips both keywords as before.

Tests, in `ValidatorConstraintsDescriberTest`:

- `Positive` now expects `['exclusiveMinimum' => 0]` instead of the boolean form.
- `Numeric range` (`GreaterThan(10)`, `LessThanOrEqual(100)`, `DivisibleBy(3)`) now expects `'exclusiveMinimum' => 10` next to `'maximum' => 100`.
- `Negative`, new, with a new `#[Negative]` property in `ValidatorConstraintsFixture`, covers the upper bound: `['exclusiveMaximum' => 0]`.

All three fail without the change.

Reproduction:

```php
final class Place
{
    public function __construct(
        #[Positive]
        public int $line,
    ) {
    }
}

$line = (new ResponseFormatFactory())->create(Place::class)['json_schema']['schema']['properties']['line'];
echo json_encode($line);
// before: {"type":"integer","minimum":0,"exclusiveMinimum":true}
// after:  {"type":"integer","exclusiveMinimum":0}
```

Out of scope: open pull requests #2448 and #2411 touch `src/platform/src/Contract/JsonSchema/` (`Factory` and the describers, `Attribute/Schema.php`), but neither changes `ValidatorConstraintsDescriber`, so they should not conflict.
