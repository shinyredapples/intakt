<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Finding;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Serializer\Exception\LogicException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

// Only on the stored serializer, because Finding::$merged is ignored for the model's schema and answer but must survive in stored JSON.
#[AutoconfigureTag('serializer.normalizer', ['serializer' => 'stored'])]
final class StoredFindingNormalizer implements NormalizerInterface, DenormalizerInterface, NormalizerAwareInterface, DenormalizerAwareInterface
{
    use DenormalizerAwareTrait;
    use NormalizerAwareTrait;

    private const string MERGED = 'merged';
    private const string INNER = 'intakt_stored_finding_inner';

    /**
     * @param array<string, mixed> $context
     *
     * @return array<mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if (!$data instanceof Finding) {
            throw new LogicException(sprintf('Only a "%s" is normalized here.', Finding::class));
        }

        $normalized = $this->normalizer->normalize($data, $format, [...$context, self::INNER => true]);
        if (!is_array($normalized)) {
            throw new LogicException(sprintf('A "%s" did not normalize to an array.', Finding::class));
        }

        if (!$this->writesMerged($context)) {
            return $normalized;
        }

        return [...$normalized, self::MERGED => $data->merged];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Finding && !isset($context[self::INNER]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        if (!is_array($data)) {
            throw new LogicException(sprintf('Only a "%s" decoded as an array is denormalized here.', Finding::class));
        }

        // Read as none when the key is missing, because every row stored before merged ids existed lacks it.
        $merged = array_key_exists(self::MERGED, $data) ? $this->idsIn($data[self::MERGED]) : [];
        unset($data[self::MERGED]);

        return $this->denormalizer->denormalize($data, Finding::class, $format, [...$context, self::INNER => true])->withMerged($merged);
    }

    // Arrays only, because the reviewer's answer is decoded into objects and must stay refused when it names merged ids.
    /**
     * @param array<string, mixed> $context
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return Finding::class === $type && is_array($data) && !isset($context[self::INNER]);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Finding::class => false];
    }

    // Honors the attributes and ignored attributes of the context, because the review command's act list rows name the fields they write.
    /**
     * @param array<string, mixed> $context
     */
    private function writesMerged(array $context): bool
    {
        $only = $context[AbstractNormalizer::ATTRIBUTES] ?? null;
        $left = $context[AbstractNormalizer::IGNORED_ATTRIBUTES] ?? [];

        return (!is_array($only) || in_array(self::MERGED, $only, true) || array_key_exists(self::MERGED, $only))
            && (!is_array($left) || !in_array(self::MERGED, $left, true));
    }

    // A stored row is read back as data, so merged ids that are not a list of strings fail instead of reading as none.
    /**
     * @return list<string>
     */
    private function idsIn(mixed $merged): array
    {
        if (!is_array($merged) || !array_is_list($merged)) {
            throw $this->notIds();
        }

        $ids = [];
        foreach ($merged as $id) {
            if (!is_string($id)) {
                throw $this->notIds();
            }

            $ids[] = $id;
        }

        return $ids;
    }

    private function notIds(): UnexpectedValueException
    {
        return new UnexpectedValueException('The merged ids of a stored finding must be a list of strings.');
    }
}
