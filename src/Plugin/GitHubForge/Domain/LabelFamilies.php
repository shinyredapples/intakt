<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use Intakt\Plugin\GitHubForge\Contract\LabelApplication;

// One value per family: the label applied last wins, as docs/design/05-architecture/04-labels.md says. `review:` takes several values, and a label without a colon is no family.
final readonly class LabelFamilies
{
    private const string SEPARATOR = ':';
    private const array MANY_VALUES = ['review'];

    /**
     * @param list<LabelApplication> $applied earliest first
     *
     * @return list<LabelApplication> in the order they were applied
     */
    public function winners(array $applied): array
    {
        $lastOfFamily = [];
        foreach ($applied as $position => $application) {
            $family = $this->familyOf($application->label);
            if (null !== $family) {
                $lastOfFamily[$family] = $position;
            }
        }

        return array_values(array_filter(
            $applied,
            fn (LabelApplication $application, int $position): bool => null === ($family = $this->familyOf($application->label)) || $lastOfFamily[$family] === $position,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * @param list<LabelApplication> $applied earliest first
     *
     * @return list<string> the labels to remove, because a later one of their family replaced them
     */
    public function losers(array $applied): array
    {
        $kept = array_map(static fn (LabelApplication $application): string => $application->label, $this->winners($applied));

        return array_values(array_map(
            static fn (LabelApplication $application): string => $application->label,
            array_filter($applied, static fn (LabelApplication $application): bool => !in_array($application->label, $kept, true)),
        ));
    }

    private function familyOf(string $label): ?string
    {
        $at = strpos($label, self::SEPARATOR);
        if (false === $at) {
            return null;
        }

        $family = substr($label, 0, $at);

        return in_array($family, self::MANY_VALUES, true) ? null : $family;
    }
}
