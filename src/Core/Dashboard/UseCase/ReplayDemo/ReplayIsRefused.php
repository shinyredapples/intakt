<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ReplayDemo;

use RuntimeException;

final class ReplayIsRefused extends RuntimeException
{
    // The code of a refusal that only means another Play came first, so the page of that first Play is not told its run failed.
    private const int ANOTHER_PLAY = 1;

    public function meansAnotherPlayCameFirst(): bool
    {
        return self::ANOTHER_PLAY === $this->getCode();
    }

    public static function whileAnotherReplayRuns(): self
    {
        return new self('Another replay is running; start this one when it has ended.', self::ANOTHER_PLAY);
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
        return new self(sprintf('The ledger already holds model call %s of this recording, so it is not replayed.', $call), self::ANOTHER_PLAY);
    }
}
