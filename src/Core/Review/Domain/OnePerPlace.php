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
    // The links are the judge's as CausesInOneHunk kept them, so only findings in one hunk of one file are linked. The groups come from the places and the links alone, so which findings share an entry does not depend on the panel order; that order only picks the entry's id and text, because the first reviewer's text is the one kept. Only act ids count, so a P3 the judge marked otherwise never joins an act entry (a P1 or P2 is always an act id).
    /**
     * @param list<Finding>         $reported
     * @param list<string>          $actIds
     * @param array<string, string> $sameCause
     *
     * @return list<Finding>
     */
    public function actList(array $reported, array $actIds, array $sameCause = []): array
    {
        $act = array_values(array_filter($reported, static fn (Finding $finding): bool => in_array($finding->id, $actIds, true)));
        $places = $this->groupedByPlace($act);
        $links = $this->linksBetween($act, $sameCause);
        $causes = $this->joinedByCause($places, $links);
        $groups = $this->placesWhereACauseReachesTooFar($causes, $places, $act);

        return $this->entries($act, $groups);
    }

    // A group is named by the position of its first finding, so the entries keep the panel order. Places are keyed by file, then line, so no separator inside a path can make two places one.
    /**
     * @param list<Finding> $act
     *
     * @return list<int>
     */
    private function groupedByPlace(array $act): array
    {
        $first = [];
        $groups = [];
        foreach ($act as $at => $finding) {
            if (null === $finding->file || null === $finding->line) {
                $groups[] = $at;

                continue;
            }

            $groups[] = $first[$finding->file][$finding->line] ??= $at;
        }

        return $groups;
    }

    // A link names findings by id, either of which may come first in panel order; one that names a finding off the act list links nothing.
    /**
     * @param list<Finding>         $act
     * @param array<string, string> $sameCause
     *
     * @return list<array{int, int}>
     */
    private function linksBetween(array $act, array $sameCause): array
    {
        $at = array_flip(array_map(static fn (Finding $finding): string => $finding->id, $act));

        $links = [];
        foreach ($sameCause as $id => $cause) {
            if (isset($at[$id], $at[$cause])) {
                $links[] = [$at[$id], $at[$cause]];
            }
        }

        return $links;
    }

    // A link joins the whole groups of its two findings, because a finding linked to one entry may sit at the place of another.
    /**
     * @param list<int>             $groups
     * @param list<array{int, int}> $links
     *
     * @return list<int>
     */
    private function joinedByCause(array $groups, array $links): array
    {
        foreach ($links as [$one, $other]) {
            $kept = min($groups[$one], $groups[$other]);
            $joined = max($groups[$one], $groups[$other]);
            $groups = array_map(static fn (int $group): int => $group === $joined ? $kept : $group, $groups);
        }

        return $groups;
    }

    // An entry keeps only its first finding's text, so a cause that reaches further than NEARBY_LINES, through a chain of links or a shared line, is not merged: its findings fall back to their places and none of them loses its text to a far one.
    /**
     * @param list<int>     $causes
     * @param list<int>     $places
     * @param list<Finding> $act
     *
     * @return list<int>
     */
    private function placesWhereACauseReachesTooFar(array $causes, array $places, array $act): array
    {
        $members = [];
        foreach ($causes as $at => $cause) {
            $members[$cause][] = $act[$at];
        }

        return array_map(fn (int $cause, int $place): int => $this->areNearby($members[$cause]) ? $cause : $place, $causes, $places);
    }

    /**
     * @param list<Finding> $findings
     */
    private function areNearby(array $findings): bool
    {
        $lines = [];
        foreach ($findings as $finding) {
            if (null === $finding->file || null === $finding->line || $finding->file !== $findings[0]->file) {
                return false;
            }

            $lines[] = $finding->line;
        }

        return [] !== $lines && max($lines) - min($lines) <= CausesInOneHunk::NEARBY_LINES;
    }

    /**
     * @param list<Finding> $act
     * @param list<int>     $groups
     *
     * @return list<Finding>
     */
    private function entries(array $act, array $groups): array
    {
        $entries = [];
        foreach ($act as $at => $finding) {
            $group = $groups[$at];
            $entries[$group] = isset($entries[$group]) ? $this->merged($entries[$group], $finding) : $finding;
        }

        return array_values($entries);
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
