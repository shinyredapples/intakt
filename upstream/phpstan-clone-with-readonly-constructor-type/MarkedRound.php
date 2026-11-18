<?php

declare(strict_types=1);

enum Mark
{
    case Live;
    case Replay;
}

final readonly class MarkedRound
{
    public ?Mark $mark;

    public function __construct()
    {
        $this->mark = null;
    }

    public function isReplay(): bool
    {
        return Mark::Replay === $this->mark;
    }

    public function markedAs(Mark $mark): self
    {
        return clone ($this, ['mark' => $mark]);
    }
}
