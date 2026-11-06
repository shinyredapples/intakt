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
     * @param list<string>         $unread
     *
     * @return T|null
     */
    // A key in $absentAs takes its value only when the row lacks it, because rows written before the key existed stay as they were, while a present key that does not fit still skips the row; a key in $unread is never read and takes its $absentAs value.
    public function of(RecordedEvent $recorded, string $class, array $absentAs = [], array $unread = []): ?object
    {
        return $this->deserialized($recorded->event->payload, $class, [
            AbstractNormalizer::DEFAULT_CONSTRUCTOR_ARGUMENTS => [$class => $absentAs],
            AbstractNormalizer::IGNORED_ATTRIBUTES => $unread,
        ]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>|null
     */
    // Each entry under $key is read on its own and an unreadable one is left out, because one bad entry must not hide the others; null when the row has no list there.
    public function entriesOf(RecordedEvent $recorded, string $key, string $class): ?array
    {
        $row = json_decode($recorded->event->payload, true);
        $entries = is_array($row) ? ($row[$key] ?? null) : null;

        if (!is_array($entries)) {
            return null;
        }

        return array_values(array_filter(array_map(fn (array $entry): ?object => $this->deserialized(json_encode($entry, JSON_THROW_ON_ERROR), $class, []), array_filter($entries, is_array(...)))));
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $context
     *
     * @return T|null
     */
    private function deserialized(string $json, string $class, array $context): ?object
    {
        try {
            $payload = $this->serializer->deserialize($json, $class, 'json', $context);
        } catch (ExceptionInterface) {
            return null;
        }

        return $payload instanceof $class ? $payload : null;
    }
}
