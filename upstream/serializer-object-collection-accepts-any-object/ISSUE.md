Repository: symfony/symfony. Template: Bug Report (`1_Bug_report.yaml`). Not posted: the owner approves this text first. The fix looks small (see Possible Solution).

Title: [Serializer] A list of objects accepts any object, and the constructor then fails with a `TypeError`

### Symfony version(s) affected

6.4.49, 7.4.22, 8.1.9, 8.1.11 and 8.2.0-BETA1 (target branch: 6.4). Related: #51003.

### Description

For a property typed `array` with `@param list<Item>`, `AbstractObjectNormalizer::validateAndDenormalize()` asks for a denormalizer for the class `Item[]`. When the data is an object instead of an array, `ArrayDenormalizer` refuses it, and the fallback type check then compares the data against the type identifier `object` (set for the element class) instead of `array`. Any object passes that check (`TypeIdentifier::OBJECT => \is_object($data)`) and is handed to the constructor, which fails with a `TypeError` instead of the `NotNormalizableValueException` the serializer raises for every other wrong type. A `list<string>` in the same position is refused correctly.

The trigger is narrow: the data must hold an object where the list is expected, so it needs `JsonDecode::ASSOCIATIVE => false` (a JSON object then decodes to `stdClass`) or data built with objects already in it. `#[MapRequestPayload]` and the default JSON decoding produce arrays and are not affected.

### How to reproduce

```php
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class Item
{
    public function __construct(public string $name) {}
}

final class Basket
{
    /** @param list<Item> $items */
    public function __construct(public array $items) {}
}

$types = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);
$serializer = new Serializer([new ArrayDenormalizer(), new ObjectNormalizer(propertyTypeExtractor: $types)], [new JsonEncoder()]);

$serializer->deserialize('{"items": {}}', Basket::class, 'json', [JsonDecode::ASSOCIATIVE => false]);
// the same with $serializer->denormalize(['items' => new \DateTimeImmutable()], Basket::class)
```

Actual:

```
TypeError: Basket::__construct(): Argument #1 ($items) must be of type array, stdClass given
```

Expected: a `NotNormalizableValueException` saying that `items` expects an array (the message may name the type as `Item[]`).

### Possible Solution

In the fallback check, treat a class name ending in `[]` as a collection, so only an array (or an iterable) matches it, for example `TypeIdentifier::OBJECT => \is_object($data) && !(null !== $class && str_ends_with($class, '[]'))`. The `null !== $class` check is needed: a plain `object` type has a null class, and `str_ends_with(null, ...)` is deprecated. 6.4 has the same fallback in its `'is_'.$builtinType` check.

### Additional Context

_No response_
