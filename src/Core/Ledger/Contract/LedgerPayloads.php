<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

// A row that does not deserialize is skipped, never thrown, because a ledger row is never rewritten and one bad row must not stop every later read.
final readonly class LedgerPayloads
{
    public function __construct(
        #[Target('stored')]
        private SerializerInterface $serializer,
    ) {
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $absentAs
     *
     * @return T|null
     */
    // A key in $absentAs takes its value only when the row lacks it, because rows written before the key existed stay as they were, while a present key that does not fit still skips the row.
    public function of(RecordedEvent $recorded, string $class, array $absentAs = []): ?object
    {
        try {
            $payload = $this->serializer->deserialize($recorded->event->payload, $class, 'json', [AbstractNormalizer::DEFAULT_CONSTRUCTOR_ARGUMENTS => [$class => $absentAs]]);
        } catch (ExceptionInterface) {
            return null;
        }

        return $payload instanceof $class ? $payload : null;
    }
}
