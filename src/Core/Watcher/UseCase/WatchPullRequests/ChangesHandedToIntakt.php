<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\Forge\Contract\Opening;
use JsonException;

// Decided from Intakt's own push records alone, because a pull request's author, title, labels and branch are text others can shape: docs/architecture/handed-to-intakt.md
final readonly class ChangesHandedToIntakt
{
    public function __construct(
        private Ledger $ledger,
        private WatcherLedger $records,
    ) {
    }

    /**
     * @param list<OpenChange> $open
     *
     * @throws WatcherEventNotRecorded
     */
    public function recognizeAmong(string $project, array $open): void
    {
        if ([] === $open) {
            return;
        }

        $waiting = array_diff_key($this->addressesOf($project, LedgerEventKind::ChangeOpened), $this->addressesOf($project, LedgerEventKind::ChangeHandedToIntakt));

        foreach ($open as $change) {
            $address = $this->addressOf($change->id->value);

            if (isset($waiting[$address])) {
                $this->records->changeHandedToIntakt($project, $change);
                unset($waiting[$address]);
            }
        }
    }

    // Only an opening that created the pull request, because Intakt's push also updates an open pull request someone else opened on the same branch.
    /**
     * @return array<string, true>
     */
    private function addressesOf(string $project, LedgerEventKind $kind): array
    {
        $addresses = [];

        foreach ($this->ledger->readOfKind($kind) as $recorded) {
            $payload = $this->decoded($recorded->event->payload);
            $change = $payload['change'] ?? null;
            $created = LedgerEventKind::ChangeOpened !== $kind || Opening::Created->value === ($payload['opening'] ?? null);

            if ($created && $project === ($payload['project'] ?? null) && is_string($change)) {
                $addresses[$this->addressOf($change)] = true;
            }
        }

        return $addresses;
    }

    // Without case, because the push names the repository as configured and the listing as the forge spells it, and forge addresses do not tell owners or repositories apart by case.
    private function addressOf(string $change): string
    {
        return strtolower($change);
    }

    /**
     * @return array<mixed>
     */
    private function decoded(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Skipped, because a ledger row is never rewritten and one bad row must not stop a tick.
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
