<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\Uid\Uuid;

// A port, because Review only says what happened and whoever shows it live is not its concern; an implementation never throws, because a lost update must not change a verdict or a round.
interface RoundUpdates
{
    public function toolCalled(Uuid $round, string $reviewer, string $tool): void;

    public function textStreamed(Uuid $round, string $reviewer, string $text): void;

    // Null when the answer could not be read as a verdict, because the page then says so and never shows the raw answer.
    public function answerGiven(Uuid $round, string $reviewer, ?Verdict $verdict): void;

    // Named by the retry's conversation id, because a retry that falls back to an unstreamed call reports its start twice and must be shown once.
    public function reviewerAskedAgain(Uuid $round, string $reviewer, string $try): void;

    public function roundChanged(Uuid $round, RoundProgress $progress): void;

    // After what the summary counts is stored, so a listener recomputes the summary of the round.
    public function summaryChanged(Uuid $round): void;

    public function reviewerStopped(Uuid $round, string $reviewer): void;

    // After the start is stored, so a listener that reads who is working sees the reviewer running.
    public function reviewerStarted(Uuid $round, string $reviewer): void;
}
