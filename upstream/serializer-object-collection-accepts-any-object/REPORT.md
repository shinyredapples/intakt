# [Serializer] A list of objects accepts a `stdClass` and ends in a `TypeError`

Filed as pull request symfony/symfony#67913 (merged into 6.4 on 2026-11-06, released in v8.2.0-BETA2). Affected: 6.4.49, 7.4.22, 8.1.9, 8.1.11 and 8.2.0-BETA1; target branch: 6.4. Versions: `symfony/serializer` v8.1.9, `symfony/property-info` v8.1.8, PHP 8.5.12.

## What happens

With `JsonDecode::ASSOCIATIVE` set to false, a JSON object decodes to `stdClass`. For a constructor parameter typed `array` with `@param list<Item>`, `AbstractObjectNormalizer::validateAndDenormalize()` looks for a denormalizer for `Item[]`; `ArrayDenormalizer` refuses non-arrays, and the fallback type check then compares against `object` (the identifier set for the element class) instead of `array`, so the `stdClass` is passed on. The constructor fails with a `TypeError` instead of a `NotNormalizableValueException`:

```
TypeError instead of NotNormalizableValueException: Basket::__construct(): Argument #1 ($items) must be of type array, stdClass given
```

A list of strings in the same position (`@param list<string>`) is refused with a `NotNormalizableValueException` as expected.

The trigger is narrow: an object where the list is expected, which needs `JsonDecode::ASSOCIATIVE => false` or data built with objects in it (`['items' => new DateTimeImmutable()]` fails the same way). `#[MapRequestPayload]` and the default JSON decoding produce arrays and are not affected.

## Expected

A `NotNormalizableValueException` saying the attribute expects an array (the message may name the type as `Item[]`).

## Effect in Intakt

`src/Core/Review/Infrastructure/StrictVerdictConverter.php` turns the `TypeError` raised while mapping a verdict into a mapping failure, so `{"findings": {}}` becomes a `changes` verdict.

## Upstream status

Checked on 2026-11-02, refreshed on 2026-11-06.

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/serializer` v8.1.9: `still fails  serializer-object-collection-accepts-any-object`.
- **Development branch:** still fails on both branches. The same run in a scratch copy with `symfony/serializer` at `8.1.x-dev` (d627c85fa91e) and, in another, every Symfony component at `8.2.x-dev` (`symfony/serializer` 744d620413a5): `still fails  serializer-object-collection-accepts-any-object` in each.
- **Existing issue or pull request:** none found. Candidates in `symfony/symfony`, none the same bug: #64444 (closed issue, an `iterable` constructor argument not denormalized after 7.4.10), #42226 (closed issue, the `object` type), #60036 (closed issue, nested lists), #47220 (closed issue, empty list and empty object), #47012 (closed issue, default context). Related: #51003.
- **Searches** (`symfony/symfony` issues and pull requests, open and closed): `label:Serializer stdClass TypeError`, `validateAndDenormalize stdClass`, `ArrayDenormalizer stdClass`, `label:Serializer "JsonDecode::ASSOCIATIVE"`, `serializer list object TypeError NotNormalizableValueException`.
- **Intakt lock (2026-11-16):** passes. `php bin/console intakt:upstream:run` at `symfony/serializer` v8.2.0-BETA2: `passes       serializer-object-collection-accepts-any-object`, the `Item[]` attribute now refuses a `stdClass` (`must be one of "Item[]" ("stdClass" given)`). Fixed in 8.2.0-BETA2 (8.2.0-BETA1 still failed, see the refresh above); fixed by symfony/symfony#67913 (merged into 6.4 on 2026-11-06). 6.4 and 7.4 were not rerun.
- **Pull requests and commits:** filed as pull request #67913 by Intakt (merged 2026-11-06, bug fix `[Serializer] Refuse an object where an array of objects is expected`), commit bb3f6a05be on 6.4, merged up into 7.4, 8.1 and 8.2 (checked 2026-11-15 at `symfony/symfony` 8.2 979969dc83). `AbstractObjectNormalizer` no longer returns an object as is when the declared type is an array, and keeps accepting an `ArrayObject` for a collection class. Pull requests searched, open, merged and closed, with `is:pr Serializer array of objects` and `is:pr Serializer stdClass`; commits from `git log origin/<branch> -- src/Symfony/Component/Serializer/Normalizer/AbstractObjectNormalizer.php` since 2026-09-01.
- **Decision:** `filed as pull request` https://github.com/symfony/symfony/pull/67913, merged into 6.4 on 2026-11-06, released in v8.2.0-BETA2 (recorded 2026-11-20).
- **Owner review (2026-11-03, symfony/symfony 6.4 to 8.2):** an issue, with the narrow trigger stated (`JsonDecode::ASSOCIATIVE => false` or pre-built objects; `MapRequestPayload` users are not affected). The fix looks small: the fallback `TypeIdentifier::OBJECT => \is_object($data)` check accepts any object for the class `Item[]`. `reproduce.php` also fails with `symfony/serializer` v6.4.49 and v7.4.22 (scratch projects, 2026-11-03).
- **Refresh (2026-11-06):** still reproduces on 6.4.49, 7.4.22, 8.1.11 and 8.2.0-BETA1. The suggested guard is `null !== $class && str_ends_with($class, '[]')`: a plain `object` type has a null class, and `str_ends_with(null, ...)` is deprecated.
- **Status:** `filed as pull request` symfony/symfony#67913, merged into 6.4 on 2026-11-06, released in v8.2.0-BETA2 (recorded 2026-11-20): nothing more to file; `ISSUE.md` was removed. Checked 2026-11-22: still merged and in v8.2.0-BETA2, nothing more to watch.
