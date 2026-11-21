<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\FindingIds;
use Intakt\Core\Review\Contract\SameCause;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Serializer\Exception\LogicException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

// Only on the stored serializer, because judge_done rows keep finding ids as a JSON list and the judge's links as a JSON object (an empty one as []), as they were written before these values were objects.
#[AutoconfigureTag('serializer.normalizer', ['serializer' => 'stored'])]
final class StoredFindingIdsNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<int|string, string>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        return match (true) {
            $data instanceof FindingIds => [...$data],
            $data instanceof SameCause => iterator_to_array($data),
            default => throw new LogicException(sprintf('Only a "%s" or a "%s" is normalized here.', FindingIds::class, SameCause::class)),
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof FindingIds || $data instanceof SameCause;
    }

    // A stored row is read back as data, so values that are not strings fail instead of reading as none; finding ids drop their keys in order, because the code only ever wrote lists and rows were read without checking keys before the ids were one value.
    /**
     * @param array<string, mixed> $context
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        $strings = $this->stringsIn($data, $type);

        if (FindingIds::class === $type) {
            return new FindingIds(...array_values($strings));
        }

        $sameCause = new SameCause();
        foreach ($strings as $finding => $cause) {
            try {
                $sameCause = $sameCause->withStored((string) $finding, $cause);
            } catch (InvalidArgumentException $refused) {
                throw new UnexpectedValueException(sprintf('A stored "%s" holds a link it refuses: %s', $type, $refused->getMessage()), 0, $refused);
            }
        }

        return $sameCause;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return FindingIds::class === $type || SameCause::class === $type;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [FindingIds::class => true, SameCause::class => true];
    }

    /**
     * @return array<int|string, string>
     */
    private function stringsIn(mixed $data, string $type): array
    {
        if (!is_array($data)) {
            throw $this->notStrings($type);
        }

        $strings = [];
        foreach ($data as $key => $value) {
            if (!is_string($value)) {
                throw $this->notStrings($type);
            }

            $strings[$key] = $value;
        }

        return $strings;
    }

    private function notStrings(string $type): UnexpectedValueException
    {
        return new UnexpectedValueException(sprintf('A stored "%s" must be an array of strings.', $type));
    }
}
