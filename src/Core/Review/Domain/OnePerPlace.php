<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Severity;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The act list keeps one entry per place and per cause, so two reviewers flagging one place, or one change at nearby lines, send the builder one fix; a rule, not a service.
#[Exclude]
final readonly class OnePerPlace
{
    // The judge names a cause, but plain code merges only nearby lines of one file, so a steered judge cannot fold a defect elsewhere into another entry.
    public const int SAME_CAUSE_LINES = 10;

    // The reported findings come in panel order, because the first reviewer's text is the one kept; only act ids count, so a P3 the judge marked otherwise never joins an act entry (a P1 or P2 is always an act id).
    /**
     * @param list<Finding>         $reported
     * @param list<string>          $actIds
     * @param array<string, string> $sameCause the judge's link from a finding's id to the id of another finding with the same cause
     *
     * @return list<Finding>
     */
    public function actList(array $reported, array $actIds, array $sameCause = []): array
    {
        $acting = array_values(array_filter($reported, static fn (Finding $finding): bool => in_array($finding->id, $actIds, true)));
        $entries = [];
        $places = [];
        $entryOf = [];

        foreach ($acting as $finding) {
            $at = $this->entryAt($places, $finding) ?? $this->entryOfSameCause($entryOf, $acting, $finding, $sameCause);
            if (null !== $at) {
                $entries[$at] = $this->merged($entries[$at], $finding);
                $entryOf[$finding->id] = $at;

                continue;
            }

            $entries[] = $finding;
            $entryOf[$finding->id] = array_key_last($entries);
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
     * @param array<string, int>    $entryOf
     * @param list<Finding>         $acting
     * @param array<string, string> $sameCause
     */
    private function entryOfSameCause(array $entryOf, array $acting, Finding $finding, array $sameCause): ?int
    {
        foreach ($acting as $earlier) {
            $linked = ($sameCause[$finding->id] ?? null) === $earlier->id || ($sameCause[$earlier->id] ?? null) === $finding->id;
            if ($linked && isset($entryOf[$earlier->id]) && $this->nearby($earlier, $finding)) {
                return $entryOf[$earlier->id];
            }
        }

        return null;
    }

    private function nearby(Finding $one, Finding $other): bool
    {
        if (null === $one->file || null === $one->line || null === $other->line || $one->file !== $other->file) {
            return false;
        }

        return abs($one->line - $other->line) <= self::SAME_CAUSE_LINES;
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
