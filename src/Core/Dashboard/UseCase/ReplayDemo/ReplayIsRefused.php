<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ReplayDemo;

use RuntimeException;

final class ReplayIsRefused extends RuntimeException
{
    // The code of a refusal that only means another Play came first, so the refused Play's page points at that run instead of reporting a failure.
    private const int ANOTHER_PLAY = 1;

    // The code of a busy replay lock, which `intakt:demo:remove` takes as well, so the refused Play's page cannot claim that a run exists.
    private const int LOCK_BUSY = 2;

    // The code of a model call another review already used, so the refused Play's page says so without pointing at a run that may never have played.
    private const int CALL_USED_ELSEWHERE = 3;

    public function meansAnotherPlayCameFirst(): bool
    {
        return self::ANOTHER_PLAY === $this->getCode();
    }

    public function meansAnotherReviewUsedACall(): bool
    {
        return self::CALL_USED_ELSEWHERE === $this->getCode();
    }

    public function meansTheReplayLockIsBusy(): bool
    {
        return self::LOCK_BUSY === $this->getCode();
    }

    public static function whileAnotherReplayRuns(): self
    {
        return new self('Another replay or a demo removal is running; start this one when it has ended.', self::LOCK_BUSY);
    }

    public static function becauseTheDemoProjectIsNoReplay(string $project): self
    {
        return new self(sprintf('The demo project %s is not marked as a replay, so nothing is replayed; create it with intakt:demo:create first.', $project));
    }

    public static function becauseAnUpdateHasNoTopic(int $number): self
    {
        return new self(sprintf('Captured update %d of this recording has no topic, so nothing is replayed.', $number + 1));
    }

    public static function becauseTheLedgerHolds(string $round): self
    {
        return new self(sprintf('The ledger already holds review round %s of this recording, so it is not replayed again.', $round), self::ANOTHER_PLAY);
    }

    public static function becauseTheLedgerHoldsCall(string $call): self
    {
        return new self(sprintf('The ledger already holds model call %s of this recording from an earlier replay, so it is not replayed again.', $call), self::ANOTHER_PLAY);
    }

    public static function becauseAnotherReviewHoldsCall(string $call): self
    {
        return new self(sprintf('Model call %s of this recording is already used by another review in the ledger, so it is not replayed.', $call), self::CALL_USED_ELSEWHERE);
    }
}
