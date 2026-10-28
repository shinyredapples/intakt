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
