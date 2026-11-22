<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

use JsonException;

// An unreadable payload decodes to nothing, because a ledger row is never rewritten and one bad row must not take a page down or stop a publication.
final readonly class LedgerPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function of(RecordedEvent $recorded): array
    {
        try {
            $payload = json_decode($recorded->event->payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($payload) ? array_filter($payload, is_string(...), ARRAY_FILTER_USE_KEY) : [];
    }
}
