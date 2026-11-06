<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
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
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public function of(RecordedEvent $recorded, string $class): ?object
    {
        try {
            $payload = $this->serializer->deserialize($recorded->event->payload, $class, 'json');
        } catch (ExceptionInterface) {
            return null;
        }

        return $payload instanceof $class ? $payload : null;
    }
}
