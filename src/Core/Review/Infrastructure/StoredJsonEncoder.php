<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use stdClass;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

// Only on the stored serializer: decoding as arrays turns the object {"0":"B"} into the list ["B"], so a merged value that is an object is kept as one for StoredFindingNormalizer to refuse; the rule holds at any depth because decoding knows no target type, so only stored findings may use a merged key.
#[AutoconfigureTag('serializer.encoder', ['serializer' => 'stored', 'priority' => 10])]
final class StoredJsonEncoder extends JsonEncoder
{
    private const string MERGED = 'merged';

    /**
     * @param array<string, mixed> $context
     */
    public function decode(string $data, string $format, array $context = []): mixed
    {
        $decoded = parent::decode($data, $format, [...$context, JsonDecode::ASSOCIATIVE => false]);

        return $this->asArrays($decoded, null);
    }

    private function asArrays(mixed $value, int|string|null $key): mixed
    {
        if ($value instanceof stdClass) {
            if (self::MERGED === $key) {
                return $value;
            }

            $value = (array) $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        $converted = [];
        foreach ($value as $name => $item) {
            $converted[$name] = $this->asArrays($item, $name);
        }

        return $converted;
    }
}
