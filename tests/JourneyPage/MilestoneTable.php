<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;

final readonly class MilestoneTable
{
    /**
     * @param list<Milestone> $milestones
     * @param list<Issue>     $issues
     */
    public function region(array $milestones, array $issues): PageRegion
    {
        usort($milestones, static fn (Milestone $a, Milestone $b): int => [$a->due?->getTimestamp() ?? PHP_INT_MAX, $a->number] <=> [$b->due?->getTimestamp() ?? PHP_INT_MAX, $b->number]);
        $rows = array_map(fn (Milestone $milestone): string => $this->row($milestone, $issues), $milestones);

        return new PageRegion('milestones', implode("\n", ['| Milestone | Goal | Due | Last issue closed | Done / open |', '|---|---|---|---|---|', ...$rows]));
    }

    /**
     * @param list<Issue> $all
     */
    private function row(Milestone $milestone, array $all): string
    {
        $issues = array_filter($all, static fn (Issue $issue): bool => $issue->milestone === $milestone->number);
        $open = count(array_filter($issues, static fn (Issue $issue): bool => $issue->isOpen()));
        $done = array_filter($issues, static fn (Issue $issue): bool => $issue->isDone());
        $last = [] === $done ? null : max(array_map(static fn (Issue $issue) => $issue->closed, $done));
        $lastText = match (true) {
            $open > 0 => 'open',
            null === $last => 'none',
            default => JourneyDates::short($last),
        };
        $due = $milestone->due instanceof DateTimeImmutable ? JourneyDates::short($milestone->due) : 'none';

        return '| '.implode(' | ', [$this->cell($milestone->title), $this->cell($milestone->goal), $due, $lastText, count($done).' / '.$open]).' |';
    }

    // An escaped `<` keeps forge text from opening a comment or writing a journey marker into the page.
    private function cell(string $text): string
    {
        return str_replace(['|', '<', "\r", "\n"], ['\|', '&lt;', '', ' '], trim($text));
    }
}
