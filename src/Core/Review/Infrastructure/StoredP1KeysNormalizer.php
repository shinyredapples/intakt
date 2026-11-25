<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\P1Keys;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Serializer\Exception\LogicException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

// Only on the stored serializer, because judge_done rows keep the P1 keys as a JSON object of strings and nulls (an empty one as []), as they were written before the keys were one value.
#[AutoconfigureTag('serializer.normalizer', ['serializer' => 'stored'])]
final class StoredP1KeysNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<int|string, ?string>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if (!$data instanceof P1Keys) {
            throw new LogicException(sprintf('Only a "%s" is normalized here.', P1Keys::class));
        }

        return iterator_to_array($data);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof P1Keys;
    }

    // A stored row is read back as data, so a key that is neither a string nor null fails instead of reading as none.
    /**
     * @param array<string, mixed> $context
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        if (!is_array($data)) {
            throw $this->notKeys();
        }

        $keys = new P1Keys();
        foreach ($data as $finding => $key) {
            if (null !== $key && !is_string($key)) {
                throw $this->notKeys();
            }

            $keys = $keys->with((string) $finding, $key);
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return P1Keys::class === $type;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [P1Keys::class => true];
    }

    private function notKeys(): UnexpectedValueException
    {
        return new UnexpectedValueException(sprintf('A stored "%s" must be a map of strings or nulls.', P1Keys::class));
    }
}
