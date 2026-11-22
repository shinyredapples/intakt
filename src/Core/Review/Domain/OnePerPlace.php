<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FindingIds;
use Intakt\Core\Review\Contract\Severity;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The act list keeps one entry per place and per cause, so two reviewers flagging one place, or one change in one hunk, send the builder one fix; a rule, not a service.
#[Exclude]
final readonly class OnePerPlace
{
    // The links are the judge's as CausesInOneHunk kept them, so only findings in one hunk of one file are linked. The groups come from the places and the links, so a group no wider than NEARBY_LINES is the same in any panel order; that order picks the entry's id and text, because the first reviewer's text is the one kept, and the order in which links are tried when the bound refuses one. Only act ids count, so a P3 the judge marked otherwise never joins an act entry (a P1 or P2 is always an act id).
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
        $groups = $this->groupedByPlace($act);
        foreach ($this->linksInPanelOrder($act, $sameCause) as $link) {
            $groups = $this->joinedWhenNearby($groups, $act, $link);
        }

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

    // Links are applied in the panel order of the linking finding, so when the bound refuses one of them the result is still the same on every run. A link that names a finding off the act list links nothing.
    /**
     * @param list<Finding>         $act
     * @param array<string, string> $sameCause
     *
     * @return list<array{int, int}>
     */
    private function linksInPanelOrder(array $act, array $sameCause): array
    {
        $at = array_flip(array_map(static fn (Finding $finding): string => $finding->id, $act));

        $links = [];
        foreach ($act as $one => $finding) {
            $cause = $sameCause[$finding->id] ?? null;
            if (null !== $cause && isset($at[$cause])) {
                $links[] = [$one, $at[$cause]];
            }
        }

        return $links;
    }

    // A link joins the whole groups of its two findings, because a finding linked to one entry may sit at the place of another. An entry keeps only its first finding's text, so a link whose joined group would leave one file or reach further than NEARBY_LINES is ignored: both groups stay entries and no far finding loses its text, even through a chain of links that each pass CausesInOneHunk.
    /**
     * @param list<int>       $groups
     * @param list<Finding>   $act
     * @param array{int, int} $link
     *
     * @return list<int>
     */
    private function joinedWhenNearby(array $groups, array $act, array $link): array
    {
        [$one, $other] = $link;
        $kept = min($groups[$one], $groups[$other]);
        $joined = max($groups[$one], $groups[$other]);
        $candidate = array_map(static fn (int $group): int => $group === $joined ? $kept : $group, $groups);
        $members = array_values(array_filter($act, static fn (int $at): bool => $candidate[$at] === $kept, ARRAY_FILTER_USE_KEY));

        return $this->areNearby($members) ? $candidate : $groups;
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

    // The entry's own note first, then the first one of a finding it merged, because a P1 the judge rejected may be reported second at a place and its reason must not be lost.
    private function merged(Finding $entry, Finding $finding): Finding
    {
        $merged = $entry
            ->withSeverity($this->higher($entry->severity, $finding->severity))
            ->withMerged($this->idsMergedInto($entry, $finding));
        $note = $finding->judgeNote();

        return null === $entry->judgeNote() && null !== $note ? $merged->withJudgeNote($note) : $merged;
    }

    // The same id may be reported twice, so an id joins the list once and the entry never lists its own.
    private function idsMergedInto(Finding $entry, Finding $finding): FindingIds
    {
        $ids = [...$entry->merged];
        foreach ([$finding->id, ...$finding->merged] as $id) {
            if ($id !== $entry->id && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return new FindingIds(...$ids);
    }

    private function higher(Severity $one, Severity $other): Severity
    {
        $order = Severity::cases();

        return array_search($one, $order, true) <= array_search($other, $order, true) ? $one : $other;
    }
}
