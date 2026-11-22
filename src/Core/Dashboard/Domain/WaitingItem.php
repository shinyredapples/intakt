<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\OutageNotice;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The character is the role who keeps the item's part of the loop running, so the card shows who waits on the owner.
#[Exclude]
final readonly class WaitingItem
{
    private const int MINUTE = 60;
    private const int HOUR = 3600;
    private const int DAY = 86400;

    public function __construct(
        public string $lead,
        public string $consequence,
        public DateTimeImmutable $since,
        public string $linkLabel,
        public ?string $link,
        public string $linkOutcome,
        public Character $character,
    ) {
    }

    public static function ofOutage(OutageNotice $notice, ?string $statusPage): self
    {
        $account = $notice->account->key();
        $since = $notice->lastReadingAt->format('Y-m-d H:i');

        return new self(
            'No usage of account '.$account.' has been read since '.$since.' UTC',
            'Until a reading arrives, the budget guard starts at most one call at a time on this account, and none while any of its five-hour or weekly windows that has not reset yet stands at its limit, so builds queue.',
            $notice->noticedAt,
            null === $statusPage ? 'See the queue on the board' : 'Open the provider\'s status page',
            $statusPage,
            (null === $statusPage ? 'Shows the build queue on the board.' : 'Shows whether the provider reports trouble.').' This card leaves when readings return.',
            Character::Maintainer,
        );
    }

    // Whole units, rounded down, because an item must never read as older than it is.
    public function waitingFor(DateTimeImmutable $now): string
    {
        $seconds = max(0, $now->getTimestamp() - $this->since->getTimestamp());

        return match (true) {
            $seconds < self::MINUTE => 'Waiting less than a minute',
            $seconds < self::HOUR => 'Waiting '.intdiv($seconds, self::MINUTE).' min',
            $seconds < self::DAY => 'Waiting '.intdiv($seconds, self::HOUR).' h '.intdiv($seconds % self::HOUR, self::MINUTE).' min',
            default => 'Waiting '.intdiv($seconds, self::DAY).' d '.intdiv($seconds % self::DAY, self::HOUR).' h',
        };
    }
}
