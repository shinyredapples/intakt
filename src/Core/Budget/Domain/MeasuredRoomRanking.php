<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\AccountRanking;
use Intakt\Core\Budget\Contract\Holds;
use Intakt\Core\Budget\Contract\LeftOutAccount;
use Intakt\Core\Budget\Contract\LeftOutCause;
use Intakt\Core\Budget\Contract\RankedAccounts;
use Intakt\Core\Budget\Contract\UsageWindow;
use Override;

// No tier step, because the accounts of one provider share one model, so the owner's order is the tie-break after the reset.
final readonly class MeasuredRoomRanking implements AccountRanking
{
    public function __construct(
        private Holds $holds,
        private CeilingStartGuard $ceilings,
    ) {
    }

    #[Override]
    public function rank(array $accounts): RankedAccounts
    {
        $rooms = array_map($this->ceilings->roomOf(...), $accounts);
        $verdicts = array_map($this->leftOutOf(...), $accounts, $rooms);
        $open = array_keys(array_filter($verdicts, static fn (?LeftOutAccount $left): bool => !$left instanceof LeftOutAccount));
        usort($open, static fn (int $one, int $other): int => self::sortKey($accounts[$one], $rooms[$one], $one) <=> self::sortKey($accounts[$other], $rooms[$other], $other));

        return new RankedAccounts(array_map(static fn (int $position): Account => $accounts[$position], $open), array_values(array_filter($verdicts)));
    }

    private function leftOutOf(Account $account, AccountRoom $room): ?LeftOutAccount
    {
        $coolingUntil = $this->holds->coolingDownUntil($account);

        return match (true) {
            $coolingUntil instanceof DateTimeImmutable => new LeftOutAccount($account, LeftOutCause::CoolingDown, self::endOfBoth($coolingUntil, $room)),
            UsageWindow::Weekly === $room->atCeiling => new LeftOutAccount($account, LeftOutCause::AtWeeklyCeiling, $room->resetsAt),
            UsageWindow::FiveHour === $room->atCeiling => new LeftOutAccount($account, LeftOutCause::AtShortWindowCeiling, $room->resetsAt),
            default => null,
        };
    }

    // The later of the hold's end and the reset of a fresh ceiling, because the account starts again only once both have ended; unknown when that ceiling names no reset, since the hold's end alone would promise a start the ceiling still refuses.
    private static function endOfBoth(DateTimeImmutable $coolingUntil, AccountRoom $room): ?DateTimeImmutable
    {
        if (!$room->atCeiling instanceof UsageWindow) {
            return $coolingUntil;
        }

        return $room->resetsAt instanceof DateTimeImmutable ? max($coolingUntil, $room->resetsAt) : null;
    }

    // Subscription before paid, because a paid account is never spent while a subscription has room; trusted before unknown, because an old reading may hide a spent window; soonest reset next, because room left at a reset is lost.
    /**
     * @return list<int>
     */
    private static function sortKey(Account $account, AccountRoom $room, int $position): array
    {
        return [$account->provider->spendsAPlan() ? 0 : 1, $room->trusted ? 0 : 1, $room->resetsAt instanceof DateTimeImmutable ? $room->resetsAt->getTimestamp() : PHP_INT_MAX, $position];
    }
}
