<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Severity;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The act list keeps one entry per file and line, so two reviewers flagging one place send the builder one fix; a rule, not a service.
#[Exclude]
final readonly class OnePerPlace
{
    // The reported findings come in panel order, because the first reviewer's text is the one kept; only act ids count, so a finding the judge marked otherwise never joins an act entry.
    /**
     * @param list<Finding> $reported
     * @param list<string>  $actIds
     *
     * @return list<Finding>
     */
    public function actList(array $reported, array $actIds): array
    {
        $entries = [];
        $places = [];

        foreach (array_filter($reported, static fn (Finding $finding): bool => in_array($finding->id, $actIds, true)) as $finding) {
            $at = $this->entryAt($places, $finding);
            if (null !== $at) {
                $entries[$at] = $this->merged($entries[$at], $finding);

                continue;
            }

            $entries[] = $finding;
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

    private function merged(Finding $entry, Finding $finding): Finding
    {
        if ($finding->id === $entry->id || in_array($finding->id, $entry->merged, true)) {
            return $entry;
        }

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
