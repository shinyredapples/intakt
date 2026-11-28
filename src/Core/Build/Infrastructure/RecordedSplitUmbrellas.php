<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Infrastructure;

use Intakt\Core\Build\Contract\FinishedSplit;
use Intakt\Core\Build\Contract\FinishedSplits;
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

        foreach ($this->recordsOf($project, LedgerEventKind::TaskSplitPlanned) as $fields) {
            $numbers[] = $this->taskIn($fields);
        }

        return new UmbrellaTasks(...array_filter($numbers));
    }

    // A record whose parts are not a JSON list of issue numbers names no split, because closing an umbrella on parts nobody can read would close it while a part is open.
    public function finishedOf(string $project): FinishedSplits
    {
        $splits = [];

        foreach ($this->recordsOf($project, LedgerEventKind::TaskSplit) as $fields) {
            $umbrella = $this->taskIn($fields);
            $parts = $this->partsIn($fields, $umbrella);
            if (0 !== $umbrella && [] !== $parts) {
                $splits[] = new FinishedSplit($umbrella, ...$parts);
            }
        }

        return new FinishedSplits(...$splits);
    }

    /**
     * @return list<stdClass>
     */
    private function recordsOf(string $project, LedgerEventKind $kind): array
    {
        $records = [];

        foreach ($this->ledger->readOfKindsMentioning(new Mentions(json_encode($project, JSON_THROW_ON_ERROR)), $kind) as $recorded) {
            $fields = json_decode($recorded->event->payload);
            if ($fields instanceof stdClass && $project === ($fields->project ?? null)) {
                $records[] = $fields;
            }
        }

        return $records;
    }

    // Zero when the task is no positive number written as text, which no issue has.
    private function taskIn(stdClass $fields): int
    {
        $task = $fields->task ?? null;

        return is_string($task) && 1 === preg_match('/^[1-9][0-9]*$/D', $task) ? (int) $task : 0;
    }

    // Empty unless every part is an issue number other than the umbrella's; a JSON object decodes to no array, so only a list passes.
    /**
     * @return list<int>
     */
    private function partsIn(stdClass $fields, int $umbrella): array
    {
        $parts = $fields->parts ?? null;
        if (!is_array($parts)) {
            return [];
        }

        $numbers = array_values(array_filter($parts, static fn (mixed $part): bool => is_int($part) && $part > 0 && $part !== $umbrella));

        return count($numbers) === count($parts) ? $numbers : [];
    }
}
