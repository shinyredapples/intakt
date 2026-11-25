<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;

// The last applied wins, because the design gives each of its one-value families one value (docs/design/05-architecture/04-labels.md); any other prefix (`review:`, `status:`, a project's own `area:`) is no such family, so none of its labels is ever removed.
final readonly class LabelFamilies
{
    private const string SEPARATOR = ':';
    private const string MODEL = 'model';
    private const array ONE_VALUE = ['priority', 'class', self::MODEL, 'plan'];

    /**
     * @param list<LabelApplication> $applied
     *
     * @return list<LabelApplication>
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
     * @param list<LabelApplication> $applied
     *
     * @return list<string>
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
        // A `model:` label that names no tier steers nothing, so it may neither displace a tier nor have a tier removed for it.
        if (self::MODEL === $family && !Tier::fromLabel($label) instanceof Tier) {
            return null;
        }

        return in_array($family, self::ONE_VALUE, true) ? $family : null;
    }
}
