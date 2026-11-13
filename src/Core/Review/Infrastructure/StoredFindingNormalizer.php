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
     * @return array<string, mixed>
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
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): Finding
    {
        if (!is_array($data)) {
            throw new UnexpectedValueException(sprintf('A stored "%s" must be an object.', Finding::class));
        }

        // A row written before merged ids existed has no key and reads with none.
        $merged = array_key_exists(self::MERGED, $data) ? $this->idsIn($data[self::MERGED]) : [];
        unset($data[self::MERGED]);
        $finding = $this->denormalizer->denormalize($data, Finding::class, $format, [...$context, self::INNER => true]);
        if (!$finding instanceof Finding) {
            throw new UnexpectedValueException(sprintf('A stored finding did not read as a "%s".', Finding::class));
        }

        return $finding->withMerged($merged);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return Finding::class === $type && !isset($context[self::INNER]);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Finding::class => false];
    }

    // A caller that names the fields it writes (the review command's act list rows) or leaves some out gets merged ids only when it asks for them, as the inner normalizer does for every other field.
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
        if (!is_array($merged) || !array_is_list($merged) || !array_all($merged, static fn (mixed $id): bool => is_string($id))) {
            throw new UnexpectedValueException('The merged ids of a stored finding must be a list of strings.');
        }

        /* @var list<string> $merged */
        return $merged;
    }
}
