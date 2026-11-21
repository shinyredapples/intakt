<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\ClearHalt;

use Intakt\Core\Mcp\Contract\OwnerToken;
use Intakt\Core\Watchdog\Contract\OwnerCard;
use Intakt\Core\Watchdog\Infrastructure\HaltNotRecorded;
use Intakt\Core\Watchdog\Infrastructure\LedgerHalts;
use SensitiveParameter;

// The owner is whoever holds the owner token the owner server accepts, so no job, model or forge comment can clear a halt.
final readonly class ClearHalt
{
    public function __construct(
        private LedgerHalts $halts,
        private OwnerToken $owner,
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
        $standing = $this->standingCard($card);
        $this->halts->clear($standing);

        return $standing;
    }

    private function mustBeTheOwner(#[SensitiveParameter] string $presentedToken): void
    {
        $token = $this->owner->value();
        // An empty owner token means none is set up, so no answer may match it.
        if ('' === $token || !hash_equals($token, $presentedToken)) {
            throw new OnlyTheOwnerClearsAHalt('Only the owner clears a halt, and the token given is not the owner token.');
        }
    }

    private function standingCard(string $card): OwnerCard
    {
        return array_find($this->halts->standing(), static fn (OwnerCard $standing): bool => $standing->id->toRfc4122() === strtolower($card))
            ?? throw new OnlyAStandingCardIsCleared(sprintf('Only a standing card is cleared, and no standing card has the id %s.', $card));
    }
}
