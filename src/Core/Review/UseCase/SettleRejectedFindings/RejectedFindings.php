<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\SettleRejectedFindings;

use Intakt\Core\Memory\Contract\CodeSnapshot;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Infrastructure\SettledCode;
use Symfony\Component\Messenger\MessageBusInterface;

// Only the orchestrator settles a finding, from the judge's reject or a rejecting escalation review; a finding without a file, or whose file cannot be hashed, is not settled, because nothing could tell when its code changed.
final readonly class RejectedFindings
{
    public const string JUDGE_GAVE_NO_REASON = 'The judge rejected it without a reason.';

    public function __construct(
        private SettledCode $code,
        private Redactor $redactor,
        private MessageBusInterface $commands,
    ) {
    }

    // Taken before the round is announced judged, because the checkout the hash is read from is removed then.
    /**
     * @return list<SettleRejectedFinding>
     */
    public function ofJudgement(ReviewRound $round, Judgement $judgement, JudgedFindings $judged): array
    {
        $settle = [];

        foreach ($judged->rejected as $finding) {
            $code = null === $finding->file ? null : $this->code->inRoot($round->checkoutRoot(), $finding->file);
            $settle[] = $this->settling($round->project(), $finding->withJudgeNote($this->judgeReasonFor($judgement, $finding)), $code);
        }

        return array_values(array_filter($settle));
    }

    // The escalation's reasons ride in the judge note's place, so both rejections are stored alike.
    public function ofEscalation(string $project, Finding $rejected, ?string $hash): ?SettleRejectedFinding
    {
        $code = null === $rejected->file || null === $hash ? null : $this->code->recorded($rejected->file, $hash);

        return $this->settling($project, $rejected, $code);
    }

    public function send(SettleRejectedFinding ...$settle): void
    {
        foreach ($settle as $message) {
            $this->commands->dispatch($message);
        }
    }

    private function judgeReasonFor(Judgement $judgement, Finding $finding): string
    {
        $note = array_find($judgement->marks, static fn (JudgedFinding $judged): bool => $judged->id === $finding->id)?->note;

        return null === $note || '' === trim($note) ? self::JUDGE_GAVE_NO_REASON : trim($note);
    }

    // Redacted, because finding text and reasons are model text that may quote a held credential, and memory keeps and shows them.
    private function settling(string $project, Finding $finding, ?CodeSnapshot $code): ?SettleRejectedFinding
    {
        if (!$code instanceof CodeSnapshot) {
            return null;
        }

        $text = null === $finding->symbol ? $finding->text : $finding->symbol.': '.$finding->text;

        return new SettleRejectedFinding($project, $this->redactor->redact($text), $this->redactor->redact($finding->judgeNote() ?? self::JUDGE_GAVE_NO_REASON), $code->file, $code->hash);
    }
}
