<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\AccountPicks;
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
        private AccountPicks $picks,
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

    #[Override]
    public function record(Provider $provider, RankedAccounts $pick): void
    {
        $this->picks->record($provider, $pick);
    }

    private function leftOutOf(Account $account, AccountRoom $room): ?LeftOutAccount
    {
        $coolingUntil = $this->holds->coolingDownUntil($account);
        $ceiling = match ($room->atCeiling) {
            UsageWindow::Weekly => LeftOutCause::AtWeeklyCeiling,
            UsageWindow::FiveHour => LeftOutCause::AtShortWindowCeiling,
            null => null,
        };

        if ($coolingUntil instanceof DateTimeImmutable && $this->holdEndsLast($coolingUntil, $ceiling, $room)) {
            return new LeftOutAccount($account, LeftOutCause::CoolingDown, $coolingUntil);
        }

        return $ceiling instanceof LeftOutCause ? new LeftOutAccount($account, $ceiling, $room->resetsAt) : null;
    }

    // The hold or a fresh ceiling, whichever ends last, names the cause and the reset, because the account starts again only once both have ended; a ceiling with no named reset counts as last, since the hold's end alone would promise a start the ceiling still refuses.
    private function holdEndsLast(DateTimeImmutable $coolingUntil, ?LeftOutCause $ceiling, AccountRoom $room): bool
    {
        return !$ceiling instanceof LeftOutCause || ($room->resetsAt instanceof DateTimeImmutable && $room->resetsAt <= $coolingUntil);
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
