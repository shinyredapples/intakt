# [Serializer] A list of objects accepts a `stdClass` and ends in a `TypeError`

Draft, not posted. Versions: `symfony/serializer` v8.1.9, `symfony/property-info` v8.1.8, PHP 8.5.12.

## What happens

With `JsonDecode::ASSOCIATIVE` set to false, a JSON object decodes to `stdClass`. For a constructor parameter typed `array` with `@param list<Item>`, `AbstractObjectNormalizer::validateAndDenormalize()` looks for a denormalizer for `Item[]`; `ArrayDenormalizer` refuses non-arrays, and the fallback type check then compares against `object` (the identifier set for the element class) instead of `array`, so the `stdClass` is passed on. The constructor fails with a `TypeError` instead of a `NotNormalizableValueException`:

```
TypeError instead of NotNormalizableValueException: Basket::__construct(): Argument #1 ($items) must be of type array, stdClass given
```

A list of strings in the same position (`@param list<string>`) is refused with a `NotNormalizableValueException` as expected.

## Expected

A `NotNormalizableValueException` saying the attribute expects an array.

## Effect in Intakt

`src/Core/Review/Infrastructure/StrictVerdictConverter.php` turns the `TypeError` raised while mapping a verdict into a mapping failure, so `{"findings": {}}` becomes a `changes` verdict.

## Upstream status

Checked on 2026-11-02.

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/serializer` v8.1.9: `still fails  serializer-object-collection-accepts-any-object`.
- **Development branch:** still fails on both branches. The same run in a scratch copy with `symfony/serializer` at `8.1.x-dev` (d627c85fa91e) and, in another, every Symfony component at `8.2.x-dev` (`symfony/serializer` 744d620413a5): `still fails  serializer-object-collection-accepts-any-object` in each.
- **Existing issue or pull request:** none found. Candidates in `symfony/symfony`, none the same bug: #64444 (closed issue, an `iterable` constructor argument not denormalized after 7.4.10), #42226 (closed issue, the `object` type), #60036 (closed issue, nested lists), #47220 (closed issue, empty list and empty object), #47012 (closed issue, default context).
- **Searches** (`symfony/symfony` issues and pull requests, open and closed): `label:Serializer stdClass TypeError`, `validateAndDenormalize stdClass`, `ArrayDenormalizer stdClass`, `label:Serializer "JsonDecode::ASSOCIATIVE"`, `serializer list object TypeError NotNormalizableValueException`.
- **Decision:** `ready to file`.
