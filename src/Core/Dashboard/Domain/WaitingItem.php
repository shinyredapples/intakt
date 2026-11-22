<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\OutageNotice;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// At most four choices, because more would make the owner compare instead of act, and the recommended one first because it is the one to take.
#[Exclude]
final readonly class WaitingItem
{
    private const int MOST_CHOICES = 4;

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

    // The probe is not recommended because it makes a paid call and cannot tell whether readings resumed for this account.
    public static function ofOutage(OutageNotice $notice, ?string $statusPage): self
    {
        $account = $notice->account->key();
        $since = $notice->lastReadingAt->format('Y-m-d H:i');

        return new self(
            'No usage of account '.$account.' has been read since '.$since.' UTC',
            'Until a reading arrives, the budget guard starts at most one call at a time on this account, and none while any of its five-hour or weekly windows that has not reset yet stands at its limit, so builds queue.',
            'Intakt last read the usage of '.$account.' at '.$since.' UTC. Without a reading it cannot tell how much of the limit is left. It applies to every project, and this item leaves the page when a newer reading of the account arrives.',
            $notice->noticedAt,
            [
                new WaitingChoice(
                    outcome: 'Leave it: readings usually return by themselves, and the item leaves when they do. Until then at most one call at a time starts on this account, and none while any of its five-hour or weekly windows that has not reset yet stands at its limit.',
                    command: null,
                    recommended: true,
                ),
                new WaitingChoice(
                    outcome: 'Makes one paid call per provider in your projects\' tiers; it may not include this account\'s provider. It prints each call\'s token usage, so you see whether those providers still answer, and records no usage reading.',
                    command: 'php bin/console intakt:vendor:probe',
                    recommended: false,
                ),
            ],
            null === $statusPage ? 'See the queue on the board' : 'Open the provider\'s status page',
            $statusPage,
        );
    }

    public function waitingFor(DateTimeImmutable $now): string
    {
        return 'Waiting '.new Elapsed($this->since, $now)->inWords();
    }
}
