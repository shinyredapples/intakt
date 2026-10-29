<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use NoDiscard;

enum QuorumPolicy: string
{
    case All = 'all';
    case Majority = 'majority';
    case OneStrongPlusOne = 'one-strong-plus-one';

    // Keyed by case value; PHP 8.5 takes a closure in a constant expression only as `static function`, not as an arrow function.
    private const array RULES = [
        'all' => static function (array $approvals, int $size): bool {
            return $size > 0 && count($approvals) === $size;
        },
        'majority' => static function (array $approvals, int $size): bool {
            return count($approvals) * 2 > $size;
        },
        'one-strong-plus-one' => static function (array $approvals, int $size): bool {
            return count($approvals) >= 2 && array_any($approvals, static fn (PanelVerdict $vote): bool => $vote->isStrong());
        },
    ];

    /**
     * @param list<PanelVerdict> $panel
     */
    #[NoDiscard('a quorum that is not read decides nothing')]
    public function isMet(array $panel): bool
    {
        $approvals = array_values(array_filter($panel, static fn (PanelVerdict $vote): bool => $vote->approves()));

        return self::RULES[$this->value]($approvals, count($panel));
    }
}
