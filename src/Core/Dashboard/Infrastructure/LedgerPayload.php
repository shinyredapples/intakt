<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Ledger\Contract\RecordedEvent;
use JsonException;

// An unreadable payload decodes to nothing, so one bad row does not take a page down.
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
