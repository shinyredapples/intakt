<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\ClearHalt;

use Intakt\Core\Mcp\Contract\OwnerToken;
use Intakt\Core\Watchdog\Contract\OwnerCard;
use Intakt\Core\Watchdog\Infrastructure\HaltNotRecorded;
use Intakt\Core\Watchdog\Infrastructure\LedgerHalts;
use SensitiveParameter;
use Symfony\Component\Lock\LockFactory;

// The owner is whoever holds the owner token the owner server accepts, so no job, model or forge comment can clear a halt.
final readonly class ClearHalt
{
    private const string LOCK_PREFIX = 'watchdog-halt-clear-';

    public function __construct(
        private LedgerHalts $halts,
        private OwnerToken $owner,
        private LockFactory $locks,
    ) {
    }

    /**
     * @throws OnlyTheOwnerClearsAHalt
     * @throws OnlyAStandingCardIsCleared
     * @throws HaltNotRecorded
     */
    public function cleared(string $card, #[SensitiveParameter] string $presentedToken): OwnerCard
    {
        $this->mustBeTheOwner($presentedToken);

        return $this->clearedWhileLocked(strtolower($card));
    }

    private function mustBeTheOwner(#[SensitiveParameter] string $presentedToken): void
    {
        $token = $this->owner->value();
        // An empty owner token means none is set up, so no answer may match it.
        if ('' === $token || !hash_equals($token, $presentedToken)) {
            throw new OnlyTheOwnerClearsAHalt('Only the owner clears a halt, and the token given is not the owner token.');
        }
    }

    // The read and the append under one lock per card, so of two clears of one card only one appends; tried without waiting, because the clear that finds the lock taken would only find the card cleared.
    private function clearedWhileLocked(string $card): OwnerCard
    {
        $lock = $this->locks->createLock(self::LOCK_PREFIX.$card);
        if (!$lock->acquire()) {
            throw new OnlyAStandingCardIsCleared(sprintf('Only a standing card is cleared, and card %s is being cleared by another run.', $card));
        }

        try {
            $standing = $this->standingCard($card);
            $this->halts->clear($standing);

            return $standing;
        } finally {
            $lock->release();
        }
    }

    private function standingCard(string $card): OwnerCard
    {
        return array_find($this->halts->standing(), static fn (OwnerCard $standing): bool => $standing->id->toRfc4122() === $card)
            ?? throw new OnlyAStandingCardIsCleared(sprintf('Only a standing card is cleared, and no standing card has the id %s.', $card));
    }
}
