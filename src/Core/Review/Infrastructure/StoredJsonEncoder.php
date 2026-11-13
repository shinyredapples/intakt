<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use stdClass;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

// Only on the stored serializer: decoding as arrays turns the object {"0":"B"} into the list ["B"], so a merged value that is an object is kept as one for StoredFindingNormalizer to refuse.
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

        return $this->asArrays($decoded, false);
    }

    private function asArrays(mixed $value, bool $keepObject): mixed
    {
        if ($value instanceof stdClass) {
            if ($keepObject) {
                return $value;
            }

            $value = (array) $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        $converted = [];
        foreach ($value as $key => $item) {
            $converted[$key] = $this->asArrays($item, self::MERGED === $key);
        }

        return $converted;
    }
}
