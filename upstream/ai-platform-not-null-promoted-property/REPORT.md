# [Platform] `#[NotNull]` on a promoted nullable property leaves `null` in the schema type

Draft, not filed. Versions: `symfony/ai-platform` v1.0.0-BETA1, PHP 8.5.12.

## What happens

`PropertyInfoDescriber` yields a promoted constructor property twice: once as the property (read) and once as the constructor parameter (write). `Describer::describeProperty()` runs every property describer on both subjects into the same schema. On the first pass `TypeInfoDescriber` writes `["string", "null"]` and `ValidatorConstraintsDescriber::describeNotNull()` removes `null`. On the second pass `TypeInfoDescriber` merges `["string", "null"]` back with `array_replace_recursive`, while `ValidatorConstraintsDescriber` returns early for a `ReflectionParameter`. The schema keeps `"nullable": false` beside a type that allows `null`.

`reproduce.php` builds the response format for a class with one `#[NotNull] ?string` promoted property and prints:

```
{"type":["string","null"],"nullable":false}
the type still allows null
```

## Expected

`{"type":"string","nullable":false}`: the constraint applies to the value whichever subject describes it.

## Effect in Intakt

`src/Core/Review/Contract/Finding.php` declares `title` and `kind` nullable, because findings stored before they existed have none, and puts `#[NotNull]` on both so a reviewer must give them. The schema sent to reviewers still allows `null` for both; `StrictVerdictConverter` validates the answer and refuses a `null` title or kind, so the gap costs a refused answer, never a stored finding without them. No workaround: the `#[Schema]` attribute has no `type` option to pin the type.

## Upstream status

- **Pinned versions:** still fails (`php bin/console intakt:upstream:run`: `still fails  ai-platform-not-null-promoted-property`).
- **Development branch:** the subsplit `symfony/ai-platform` `main` cd9ec2cb (2026-11-20) has the same `Describer`, `TypeInfoDescriber` and the same entry and `describeNotNull()` in `ValidatorConstraintsDescriber`, so the bug stands; `Describer` there also adds `null` to the `enum` of a property whose type allows `null`, which would put `null` into the `kind` enum too.
- **Pull requests and issues:** not searched from this build's sandbox; the duplicate check is the owner's before anything is filed.
- **Status:** `draft`, waiting for the owner's duplicate check.
