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
        public string $linkLabel,
        public ?string $link,
    ) {
        $count = count($choices);
        if ($count < 1 || $count > self::MOST_CHOICES || !$choices[0]->recommended) {
            throw WaitingItemNeedsOneToFourChoices::given($count);
        }
    }

    // Only a running outage becomes an item (OwnerNotices), so the present tense holds. Leaving it is recommended because no command brings a reading back, and the probe is offered with its cost because it only tells whether the provider answers. Without a status page (an OpenAI-compatible server) the page links to the board.
    public static function ofOutage(OutageNotice $notice, ?string $statusPage): self
    {
        $account = $notice->account->key();
        $since = $notice->lastReadingAt->format('Y-m-d H:i');

        return new self(
            'No usage of account '.$account.' has been read since '.$since.' UTC',
            'Until a reading arrives, the budget guard lets only one call at a time start on this account, so builds queue.',
            'Intakt last read the usage of '.$account.' at '.$since.' UTC. Without a reading it cannot tell how much of the limit is left. It applies to every project, and this item leaves the page when a newer reading of the account arrives.',
            $notice->noticedAt,
            [
                new WaitingChoice(
                    outcome: 'Leave it: builds keep running one call at a time until the next reading.',
                    command: null,
                    recommended: true,
                ),
                new WaitingChoice(
                    outcome: 'Makes one paid call per configured provider and prints its token usage, so you see whether the provider still answers. It records no usage reading.',
                    command: 'php bin/console intakt:vendor:probe',
                    recommended: false,
                ),
            ],
            null === $statusPage ? 'See the queue on the board' : 'Open the provider\'s status page',
            $statusPage,
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
