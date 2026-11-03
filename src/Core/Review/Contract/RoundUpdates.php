<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\Uid\Uuid;

// A port, because Review only says what happened and whoever shows it live is not its concern; an implementation never throws, because a lost update must not change a verdict or a round.
interface RoundUpdates
{
    public function toolCalled(Uuid $round, string $reviewer, string $tool): void;

    public function textStreamed(Uuid $round, string $reviewer, string $text): void;

    public function roundChanged(Uuid $round, RoundProgress $progress): void;
}
