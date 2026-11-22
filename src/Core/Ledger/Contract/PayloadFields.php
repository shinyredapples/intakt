<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

use JsonException;

// Only typed fields leave the module, so no reader elsewhere holds the decoded map, and an unreadable payload has no fields, because a ledger row is never rewritten and one bad row must not take a page down.
final readonly class PayloadFields
{
    /**
     * @param array<string, mixed> $fields
     */
    private function __construct(private array $fields)
    {
    }

    public static function of(RecordedEvent $recorded): self
    {
        try {
            $payload = json_decode($recorded->event->payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new self([]);
        }

        return new self(is_array($payload) ? array_filter($payload, is_string(...), ARRAY_FILTER_USE_KEY) : []);
    }

    public function text(string $field): ?string
    {
        $value = $this->fields[$field] ?? null;

        return is_string($value) ? $value : null;
    }

    public function number(string $field): ?int
    {
        $value = $this->fields[$field] ?? null;

        return is_int($value) ? $value : null;
    }

    public function lacks(string $field): bool
    {
        return null === ($this->fields[$field] ?? null);
    }
}
