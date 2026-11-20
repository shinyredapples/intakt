<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\FindingIds;
use WeakMap;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Serializer\Exception\LogicException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

// Only on the stored serializer, because judge_done rows keep finding ids as a JSON list, as they were written before the ids were one value.
#[AutoconfigureTag('serializer.normalizer', ['serializer' => 'stored'])]
final class StoredFindingIdsNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /** @var WeakMap<FindingIds, array<int|string, string>> */
    private WeakMap $keysRead;

    public function __construct()
    {
        $this->keysRead = new WeakMap();
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int|string, string>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if (!$data instanceof FindingIds) {
            throw new LogicException(sprintf('Only "%s" are normalized here.', FindingIds::class));
        }

        return $this->keysRead[$data] ?? [...$data];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof FindingIds;
    }

    // A stored row is read back as data, so ids that are not strings fail instead of reading as none; keys are kept as they were, because the rows were read without checking them before the ids were one value.
    /**
     * @param array<string, mixed> $context
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        if (!is_array($data)) {
            throw $this->notIds();
        }

        foreach ($data as $id) {
            if (!is_string($id)) {
                throw $this->notIds();
            }
        }

        $ids = new FindingIds(...array_values($data));
        if (!array_is_list($data)) {
            $this->keysRead[$ids] = $data;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return FindingIds::class === $type;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [FindingIds::class => true];
    }

    private function notIds(): UnexpectedValueException
    {
        return new UnexpectedValueException('Stored finding ids must be an array of strings.');
    }
}
