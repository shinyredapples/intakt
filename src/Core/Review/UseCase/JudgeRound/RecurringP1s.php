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

// Decided from every judged round of the change, not only from earlier ones, so rounds judged out of order record the same events as rounds judged in order.
final readonly class RecurringP1s
{
    public function __construct(
        private ReviewRounds $rounds,
        private Ledger $ledger,
        private LedgerPayloads $payloads,
    ) {
    }

    // Called under the change's lock after the round's judge_done is appended, so the round's own keys are read with the others and no other judging of the change commits in between.
    /**
     * @return list<RecurringP1Found>
     */
    public function foundWith(ReviewRound $round): array
    {
        $rounds = $this->headsOf($this->rounds->ofChange($round));
        $flagged = $this->flagged($rounds);
        $recorded = $this->recorded($rounds);
        $found = [];

        foreach (array_keys($flagged[$round->id()->toRfc4122()] ?? []) as $key) {
            array_push($found, ...$this->recurrencesOf((string) $key, $rounds, $flagged, $recorded));
        }

        return $found;
    }

    // A round stored before identities were recorded names no head, so it cannot tell another head from its own and is left out.
    /**
     * @param list<ReviewRound> $rounds
     *
     * @return array<string, string> head by round id, in round-number order
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

    // One scan of the judge_done events for every round of the change, because the change's lock is held while it runs.
    /**
     * @param array<string, string> $rounds
     *
     * @return array<string, array<string, string>> finding id by P1 key, by round id
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

    // Every round on another head than the first one that flagged the key gets one event, once, so a count of recurrences reads distinct heads from the rounds the events name.
    /**
     * @param array<string, string>                $rounds
     * @param array<string, array<string, string>> $flagged
     * @param array<string, array<string, true>>   $recorded
     *
     * @return list<RecurringP1Found>
     */
    private function recurrencesOf(string $key, array $rounds, array $flagged, array $recorded): array
    {
        $flagging = array_filter($rounds, static fn (string $id): bool => isset($flagged[$id][$key]), ARRAY_FILTER_USE_KEY);
        $first = (string) array_key_first($flagging);
        $found = [];

        foreach ($flagging as $id => $head) {
            if ($head !== $flagging[$first] && !isset($recorded[$id][$key])) {
                $found[] = new RecurringP1Found((string) $id, $flagged[$id][$key], $key, $first);
            }
        }

        return $found;
    }
}
