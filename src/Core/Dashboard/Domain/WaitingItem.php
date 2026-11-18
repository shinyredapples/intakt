<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\OutageNotice;

// At most four choices, because more would make the owner compare instead of act, and the recommended one first because it is the one to take.
final readonly class WaitingItem
{
    private const int MOST_CHOICES = 4;
    private const int MINUTE = 60;
    private const int HOUR = 3600;
    private const int DAY = 86400;

    /**
     * @param list<WaitingChoice> $choices
     */
    public function __construct(
        public string $lead,
        public string $consequence,
        public string $why,
        public DateTimeImmutable $since,
        public array $choices,
    ) {
        $count = count($choices);
        if ($count < 1 || $count > self::MOST_CHOICES || !$choices[0]->recommended) {
            throw WaitingItemNeedsOneToFourChoices::given($count);
        }
    }

    // The wording repeats what the budget guard does after an outage because the owner must read the real consequence: one call at a time runs on the account.
    public static function ofOutage(OutageNotice $notice): self
    {
        $account = $notice->account->key();

        return new self(
            'No usage of account '.$account.' has been read for hours',
            'Until readings return, only one call at a time runs on this account, so builds queue.',
            'Intakt last read the usage of '.$account.' at '.$notice->lastReadingAt->format('Y-m-d H:i').' UTC. Without a reading it cannot tell how much of the limit is left, so it runs one call at a time on the account. It applies to every project.',
            $notice->noticedAt,
            [
                new WaitingChoice('Shows whether the account still answers and what usage it reports.', 'bin/console intakt:vendor:probe', true),
                new WaitingChoice('Leaves it: builds keep running one call at a time.', null, false),
            ],
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
