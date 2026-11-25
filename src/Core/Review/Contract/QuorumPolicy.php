<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use NoDiscard;

enum QuorumPolicy: string
{
    case All = 'all';
    case Majority = 'majority';
    case OneStrongPlusOne = 'one-strong-plus-one';

    // Keyed by case value, and written as `static function` because PHP 8.5 refuses an arrow function in a constant expression.
    private const array RULES = [
        'all' => static function (int $size, PanelVerdict ...$approvals): bool {
            if (0 === $size) {
                return false;
            }

            return count($approvals) === $size;
        },
        'majority' => static function (int $size, PanelVerdict ...$approvals): bool {
            $half = intdiv($size, 2);

            return count($approvals) > $half;
        },
        'one-strong-plus-one' => static function (int $size, PanelVerdict ...$approvals): bool {
            if (count($approvals) < 2) {
                return false;
            }

            return array_any($approvals, static fn (PanelVerdict $vote): bool => $vote->isStrong());
        },
    ];

    public function needsStrongVote(): bool
    {
        return self::OneStrongPlusOne === $this;
    }

    /**
     * @param list<PanelVerdict> $panel
     */
    #[NoDiscard('a quorum that is not read decides nothing')]
    public function isMet(array $panel): bool
    {
        $approvals = array_values(array_filter($panel, static fn (PanelVerdict $vote): bool => $vote->approves()));

        return self::RULES[$this->value](count($panel), ...$approvals);
    }
}
