<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Contract;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Ledger\Contract\RecordedEvent;

// Read from the change_handed_to_intakt lines alone, because the watch tick appends one only after it decided from Intakt's own records and the forge's author: docs/architecture/handed-to-intakt.md
final readonly class HandedOverChanges
{
    public function __construct(
        private Ledger $ledger,
    ) {
    }

    public function isHandedToIntakt(string $project, string $change): bool
    {
        foreach ($this->ledger->readOfKind(LedgerEventKind::ChangeHandedToIntakt) as $recorded) {
            if ($this->names($recorded, $project, $change)) {
                return true;
            }
        }

        return false;
    }

    // The address without letter case, as the tick compares it, because the push names the repository as configured and the listing as the forge spells it.
    private function names(RecordedEvent $recorded, string $project, string $change): bool
    {
        $payload = PayloadFields::of($recorded);
        $named = $payload->text('change');

        return $project === $payload->text('project') && null !== $named && strtolower($named) === strtolower($change);
    }
}
