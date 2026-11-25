<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\RecordedIdentityMalformed;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewStarted;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Contract\StartReview;
use Intakt\Core\Review\Contract\StartReviewAtHead;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Plugin\Forge\Contract\CommitId;
use Symfony\Component\Messenger\MessageBusInterface;

// The next round of the change on the rejected round's head, because its reviewers get the rejection's reasons in their brief, so the holder can resolve the finding before a build is asked to fix it.
final readonly class HolderReReviews
{
    public function __construct(
        private ReviewRounds $rounds,
        private Ledger $ledger,
        private LedgerPayloads $payloads,
        private MessageBusInterface $commands,
    ) {
    }

    public function startAfterRejectionIn(string $roundId): void
    {
        $round = $this->rounds->stored($roundId);
        $head = $this->headOf($round);
        $source = $this->sourceOf($roundId);

        // Nothing to start without a head or a source, because the round is then read from no repository, and none after an earlier round on this head, because that round's holder has re-reviewed with the reasons already and holding the finding again is its answer.
        if (!$head instanceof CommitId || !$source instanceof ChangeSource || $this->reReviewedAt($round, $head)) {
            return;
        }

        try {
            $this->commands->dispatch(new StartReviewAtHead(new StartReview($round->project(), $round->task(), new RequestedRound($round->change(), $round->number() + 1, $source)), $head));
        } catch (RoundNotNext) {
            // Nothing more, because a newer round of the change, opened by another rejection or a new head, reviews it with the reasons in its brief.
            return;
        }
    }

    private function headOf(ReviewRound $round): ?CommitId
    {
        try {
            return $round->identity()->commits->head;
        } catch (RecordedIdentityMalformed) {
            return null;
        }
    }

    // Read from the round's review_started, because the stored round keeps no repository and the dashboard starts a next round from the same record.
    private function sourceOf(string $roundId): ?ChangeSource
    {
        foreach ($this->ledger->readMentioning(new Mentions($roundId)) as $recorded) {
            $started = LedgerEventKind::ReviewStarted === $recorded->event->kind ? $this->payloads->of($recorded, ReviewStarted::class) : null;

            if ($started instanceof ReviewStarted && $roundId === $started->round && null !== $started->repository && null !== $started->base) {
                return new ChangeSource($started->repository, $started->base);
            }
        }

        return null;
    }

    private function reReviewedAt(ReviewRound $round, CommitId $head): bool
    {
        return array_any($this->rounds->ofChange($round), fn (ReviewRound $other): bool => $other->number() < $round->number() && $this->headOf($other)?->hash === $head->hash);
    }
}
