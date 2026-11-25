<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundSource;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\PayloadDefaults;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\CatchUpOwed;
use Intakt\Core\Review\Contract\DroppedFinding;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\JudgeDoneFinding;
use Intakt\Core\Review\Contract\ReviewerBlocked;
use Intakt\Core\Review\Contract\ReviewerFailed;
use Intakt\Core\Review\Contract\ReviewerFindingsRecorded;
use Intakt\Core\Review\Contract\ReviewerStarted;
use Intakt\Core\Review\Contract\ReviewerStopped;
use Intakt\Core\Review\Contract\ReviewStarted;
use Intakt\Core\Review\Contract\VerdictReceived;
use Intakt\Core\ReviewerForLoop\Contract\ProjectMode;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageSettled;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageStarted;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageUnsettled;

// The writers' payload classes describe today's rows, so a row written before a key existed is read with the value the pages always showed for it; any other row that does not deserialize reads as null and is skipped.
final readonly class RoundPayloads
{
    private const array UNNAMED_START = ['change' => '', 'task' => ''];

    private const array UNMARKED_VERDICT = ['mode' => ProjectMode::Live];
    private const array UNRECORDED_FINDINGS = ['findings' => [], 'dropped' => []];

    public function __construct(private LedgerPayloads $payloads)
    {
    }

    public function startOf(RecordedEvent $recorded): ?ReviewedRound
    {
        $numbered = $this->payloads->of($recorded, ReviewStarted::class, new PayloadDefaults(...self::UNNAMED_START));

        if ($numbered instanceof ReviewStarted) {
            return $this->reviewedRound($numbered, $numbered->number, $recorded);
        }

        $unnumbered = $this->payloads->of($recorded, UnnumberedStart::class);

        return $unnumbered instanceof UnnumberedStart && null === $unnumbered->number ? $this->reviewedRound($unnumbered->asStarted(), null, $recorded) : null;
    }

    // Only the judge's marks, with no findings, because the call stands where the finding text was not recorded (rounds judged before it was) or an entry is unreadable; judgedFindingsOf() reads the findings.
    public function judgeOf(RecordedEvent $recorded): ?JudgeDone
    {
        return $this->payloads->ofWithout($recorded, JudgeDone::class, new PayloadDefaults(...self::UNRECORDED_FINDINGS));
    }

    /**
     * @return list<JudgeDoneFinding>|null
     */
    // Null when the row recorded no findings, so the page can tell a round judged before the text was recorded.
    public function judgedFindingsOf(RecordedEvent $recorded): ?array
    {
        return $this->payloads->entriesOf($recorded, 'findings', JudgeDoneFinding::class);
    }

    // False when an entry of the row's findings does not deserialize, or when an id the judge marked (on the act list, as a follow-up or in an entry's merges) is not among the findings read, because a count must not read part of the round as the whole; a row without findings is the marks-only kind the callers tell apart.
    public function isEveryJudgedFindingRecorded(RecordedEvent $recorded, JudgeDone $judge): bool
    {
        $row = json_decode($recorded->event->payload, true);
        $entries = is_array($row) ? ($row['findings'] ?? null) : null;

        if (!is_array($entries)) {
            return true;
        }

        $read = $this->judgedFindingsOf($recorded) ?? [];
        $ids = array_map(static fn (JudgeDoneFinding $finding): string => $finding->id, $read);
        $marked = array_merge([...$judge->act], [...$judge->followUps], ...array_map(static fn (JudgeDoneFinding $finding): array => [...$finding->merged], $read));

        return count($entries) === count($read) && [] === array_diff($marked, $ids);
    }

    // Empty for a row written before drop reasons were recorded, because it dropped nothing the page could show; an unreadable entry is left out, because one bad entry must not hide the others.
    /**
     * @return list<DroppedFinding>
     */
    public function droppedOf(RecordedEvent $recorded): array
    {
        return $this->payloads->entriesOf($recorded, 'dropped', DroppedFinding::class) ?? [];
    }

    public function reviewerRowOf(RecordedEvent $recorded): ReviewerStarted|VerdictReceived|ReviewerFailed|ReviewerBlocked|ReviewerStopped|CatchUpOwed|null
    {
        return match ($recorded->event->kind) {
            LedgerEventKind::ReviewerStarted => $this->payloads->of($recorded, ReviewerStarted::class),
            LedgerEventKind::VerdictReceived => $this->verdictOf($recorded),
            LedgerEventKind::ReviewerFailed => $this->payloads->of($recorded, ReviewerFailed::class),
            LedgerEventKind::ReviewerBlocked => $this->payloads->of($recorded, ReviewerBlocked::class),
            LedgerEventKind::ReviewerStopped => $this->payloads->of($recorded, ReviewerStopped::class),
            LedgerEventKind::CatchUpOwed => $this->payloads->of($recorded, CatchUpOwed::class),
            default => null,
        };
    }

    public function reviewerFindingsOf(RecordedEvent $recorded): ?ReviewerFindingsRecorded
    {
        // A severity outside the enum leaves the severities unavailable, because the reporter's finding ids must still count for them.
        return $this->payloads->of($recorded, ReviewerFindingsRecorded::class)
            ?? $this->payloads->ofWithout($recorded, ReviewerFindingsRecorded::class, new PayloadDefaults(severities: null));
    }

    // Told apart by the row's phase, because an unsettled row also reads into UsageSettled.
    public function usageOf(RecordedEvent $recorded): UsageStarted|UsageSettled|UsageUnsettled|null
    {
        $started = $this->payloads->of($recorded, UsageStarted::class);

        if ('started' === $started?->phase) {
            return $started;
        }

        $settled = $this->payloads->of($recorded, UsageSettled::class);

        if ('settled' === $settled?->phase) {
            return $settled;
        }

        $unsettled = $this->payloads->of($recorded, UsageUnsettled::class);

        return 'unsettled' === $unsettled?->phase ? $unsettled : null;
    }

    // Live when the mode is absent or unreadable, because a verdict recorded before the field existed was an ordinary one and its reviewer has answered either way.
    private function verdictOf(RecordedEvent $recorded): ?VerdictReceived
    {
        $unmarked = new PayloadDefaults(...self::UNMARKED_VERDICT);

        return $this->payloads->of($recorded, VerdictReceived::class, $unmarked)
            ?? $this->payloads->ofWithout($recorded, VerdictReceived::class, $unmarked);
    }

    private function reviewedRound(ReviewStarted $started, ?int $number, RecordedEvent $recorded): ReviewedRound
    {
        return new ReviewedRound(
            new RoundStart($started->round, $started->project, $started->reviewers),
            new RoundPlace($started->change, $number, $this->sourceOf($started)),
            $recorded->recordedAt,
            $started->quorum,
            speedFactor: $this->payloads->of($recorded, ReplayedStart::class)?->factor(),
        );
    }

    // Null unless all three are non-empty, because a start recorded before they existed must not be guessed at.
    private function sourceOf(ReviewStarted $started): ?RoundSource
    {
        $repository = $started->repository ?? '';
        $base = $started->base ?? '';

        if ('' === $repository || '' === $base || '' === $started->task) {
            return null;
        }

        return new RoundSource($repository, $base, $started->task);
    }
}
