<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\RecordedIdentityMalformed;
use Intakt\Core\Review\Contract\RecurringP1Found;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;

// A round's events are decided only once every earlier round of the change is judged, because an event is never taken back and an earlier round judged later could be the first to flag the place; so rounds judged out of order record the same events as rounds judged in order.
final readonly class RecurringP1s
{
    public function __construct(
        private ReviewRounds $rounds,
        private Ledger $ledger,
        private LedgerPayloads $payloads,
    ) {
    }

    // Called under the change's lock after the round's judge_done is appended, so no other judging of the change commits in between; it covers every settled round, not only this one, because judging an earlier round can settle later rounds judged before it.
    /**
     * @return list<RecurringP1Found>
     */
    public function foundWith(ReviewRound $round): array
    {
        $rounds = $this->headsOf($this->rounds->ofChange($round));
        $flagged = $this->flagged($rounds);
        $settled = $this->settled($rounds, $flagged);
        $recorded = $this->recorded($rounds);
        $keys = [];
        $found = [];

        foreach (array_intersect_key($flagged, $settled) as $places) {
            $keys += $places;
        }

        foreach (array_keys($keys) as $key) {
            array_push($found, ...$this->recurrencesOf($key, $settled, $flagged));
        }

        return array_values(array_filter($found, static fn (RecurringP1Found $recurrence): bool => !isset($recorded[$recurrence->round][$recurrence->key])));
    }

    // Only the rounds up to the first one not judged yet, because only for these the first round that flagged a place is final.
    /**
     * @param array<string, string>                $rounds
     * @param array<string, array<string, string>> $flagged
     *
     * @return array<string, string>
     */
    private function settled(array $rounds, array $flagged): array
    {
        $settled = [];

        foreach ($rounds as $id => $head) {
            if (!isset($flagged[$id])) {
                break;
            }

            $settled[$id] = $head;
        }

        return $settled;
    }

    // Each round's head by its id, in round-number order; a round stored before identities were recorded names no head, so it cannot tell another head from its own and is left out.
    /**
     * @param list<ReviewRound> $rounds
     *
     * @return array<string, string>
     */
    private function headsOf(array $rounds): array
    {
        $heads = [];

        foreach ($rounds as $round) {
            try {
                $heads[$round->id()->toRfc4122()] = $round->identity()->commits->head->hash;
            } catch (RecordedIdentityMalformed) {
                continue;
            }
        }

        return $heads;
    }

    // One scan of the judge_done events for every round of the change, because the change's lock is held while it runs; each round's flagged places as finding id by P1 key.
    /**
     * @param array<string, string> $rounds
     *
     * @return array<string, array<string, string>>
     */
    private function flagged(array $rounds): array
    {
        $flagged = [];

        foreach ($this->ledger->readOfKind(LedgerEventKind::JudgeDone) as $recorded) {
            $done = $this->payloads->of($recorded, JudgeDone::class);

            if ($done instanceof JudgeDone && isset($rounds[$done->round])) {
                $flagged[$done->round] = $this->placesOf($done);
            }
        }

        return $flagged;
    }

    // Only act entries with a key, because a P1 that names no regular file gets none and is judged again; a row written before keys were recorded holds none.
    /**
     * @return array<string, string>
     */
    private function placesOf(JudgeDone $done): array
    {
        $places = [];

        foreach ($done->act as $id) {
            $key = $done->p1Keys[$id] ?? null;

            if (null !== $key) {
                $places[$key] ??= $id;
            }
        }

        return $places;
    }

    /**
     * @param array<string, string> $rounds
     *
     * @return array<string, array<string, true>>
     */
    private function recorded(array $rounds): array
    {
        $recorded = [];

        foreach ($this->ledger->readOfKind(LedgerEventKind::RecurringP1Found) as $row) {
            $found = $this->payloads->of($row, RecurringP1Found::class);

            if ($found instanceof RecurringP1Found && isset($rounds[$found->round])) {
                $recorded[$found->round][$found->key] = true;
            }
        }

        return $recorded;
    }

    // Every round on another head than the first one that flagged the key, because a round on the first head flags it again on code that may not have moved; the caller leaves out the ones recorded already.
    /**
     * @param array<string, string>                $rounds
     * @param array<string, array<string, string>> $flagged
     *
     * @return list<RecurringP1Found>
     */
    private function recurrencesOf(string $key, array $rounds, array $flagged): array
    {
        $flagging = array_filter($rounds, static fn (string $id): bool => isset($flagged[$id][$key]), ARRAY_FILTER_USE_KEY);
        $first = (string) array_key_first($flagging);
        $found = [];

        foreach ($flagging as $id => $head) {
            if ($head !== $flagging[$first]) {
                $found[] = new RecurringP1Found($id, $flagged[$id][$key], $key, $first);
            }
        }

        return $found;
    }
}
