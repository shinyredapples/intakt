<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Severity;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The act list keeps one entry per place and per cause, so two reviewers flagging one place, or one change in one hunk, send the builder one fix; a rule, not a service.
#[Exclude]
final readonly class OnePerPlace
{
    // The links are the judge's as CausesInOneHunk kept them, so only findings in one hunk of one file are linked. The reported findings come in panel order, because the first reviewer's text is the one kept; only act ids count, so a P3 the judge marked otherwise never joins an act entry (a P1 or P2 is always an act id).
    /**
     * @param list<Finding>         $reported
     * @param list<string>          $actIds
     * @param array<string, string> $sameCause
     *
     * @return list<Finding>
     */
    public function actList(array $reported, array $actIds, array $sameCause = []): array
    {
        $entries = [];
        $places = [];
        $seen = [];

        foreach (array_filter($reported, static fn (Finding $finding): bool => in_array($finding->id, $actIds, true)) as $finding) {
            $at = $this->entryAt($places, $finding) ?? $this->entryOfSameCause($seen, $finding, $sameCause);
            if (null !== $at) {
                $entries[$at] = $this->merged($entries[$at], $finding);
                $seen[] = [$at, $finding];

                continue;
            }

            $entries[] = $finding;
            $seen[] = [array_key_last($entries), $finding];
            if (null !== $finding->file && null !== $finding->line) {
                $places[$finding->file][$finding->line] = array_key_last($entries);
            }
        }

        return array_values($entries);
    }

    // Keyed by file, then line, so no separator inside a path can make two places one.
    /**
     * @param array<string, array<int, int>> $places
     */
    private function entryAt(array $places, Finding $finding): ?int
    {
        if (null === $finding->file || null === $finding->line) {
            return null;
        }

        return $places[$finding->file][$finding->line] ?? null;
    }

    // Either finding of a link may come first in panel order, so a link counts in both directions.
    /**
     * @param list<array{int, Finding}> $seen
     * @param array<string, string>     $sameCause
     */
    private function entryOfSameCause(array $seen, Finding $finding, array $sameCause): ?int
    {
        foreach ($seen as [$at, $earlier]) {
            $linked = ($sameCause[$finding->id] ?? null) === $earlier->id || ($sameCause[$earlier->id] ?? null) === $finding->id;
            if ($linked) {
                return $at;
            }
        }

        return null;
    }

    private function merged(Finding $entry, Finding $finding): Finding
    {
        return $entry
            ->withSeverity($this->higher($entry->severity, $finding->severity))
            ->withMerged([...$entry->merged, $finding->id, ...$finding->merged]);
    }

    private function higher(Severity $one, Severity $other): Severity
    {
        $order = Severity::cases();

        return array_search($one, $order, true) <= array_search($other, $order, true) ? $one : $other;
    }
}
