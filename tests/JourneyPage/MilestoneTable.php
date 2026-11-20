<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;
use Intakt\Core\Platform\Contract\FigureKind;

final readonly class MilestoneTable
{
    private const string MARKDOWN_PUNCTUATION = '\\`*_{}[]()#+-.!:|"\'$>';

    /**
     * @param list<Milestone> $milestones
     * @param list<Issue>     $issues
     */
    public function region(array $milestones, array $issues): PageRegion
    {
        usort($milestones, static fn (Milestone $a, Milestone $b): int => [$a->due?->getTimestamp() ?? PHP_INT_MAX, $a->number] <=> [$b->due?->getTimestamp() ?? PHP_INT_MAX, $b->number]);
        $rows = array_map(fn (Milestone $milestone): string => $this->row($milestone, $issues), $milestones);

        $counted = new FigureSource(FigureKind::Counted, "from the issues the GitHub API lists, by each issue's milestone");

        return new PageRegion('milestones', implode("\n", ['| Milestone | Goal | Due | Last issue closed | Done / open ('.$counted->kind->value.') |', '|---|---|---|---|---|', ...$rows, '', $counted->line()]));
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

    // Forge text stays literal (no comment, journey marker or Markdown); kramdown cannot backslash-escape `~` or `&`, so they become entities, and each single pass never rewrites its own output.
    private function cell(string $text): string
    {
        return strtr(trim($text), ["\r\n" => ' ', "\r" => ' ', "\n" => ' '])
            |> (static fn (string $flat): string => addcslashes($flat, self::MARKDOWN_PUNCTUATION))
            |> (static fn (string $escaped): string => strtr($escaped, ['&' => '&amp;', '<' => '&lt;', '~' => '&#126;']));
    }
}
