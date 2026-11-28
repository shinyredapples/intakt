<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Infrastructure;

use Intakt\Core\Build\Contract\SplitUmbrellas;
use Intakt\Core\Build\Contract\UmbrellaTasks;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use stdClass;

// From the plan, not the finished split, because a split interrupted after its plan must not leave the original to be built beside its parts.
final readonly class RecordedSplitUmbrellas implements SplitUmbrellas
{
    public function __construct(
        private Ledger $ledger,
    ) {
    }

    // One read for the whole project, because intake asks every tick; a record that names the project only inside another field does not count.
    public function of(string $project): UmbrellaTasks
    {
        $numbers = [];

        foreach ($this->ledger->readOfKindsMentioning(new Mentions(json_encode($project, JSON_THROW_ON_ERROR)), LedgerEventKind::TaskSplitPlanned) as $recorded) {
            $fields = json_decode($recorded->event->payload);
            $task = $fields instanceof stdClass && $project === ($fields->project ?? null) && is_string($fields->task ?? null) ? $fields->task : '';

            if (1 === preg_match('/^[1-9][0-9]*$/D', $task)) {
                $numbers[] = (int) $task;
            }
        }

        return new UmbrellaTasks(...$numbers);
    }
}
