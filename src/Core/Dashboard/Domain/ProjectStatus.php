<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

enum ProjectStatus: string
{
    case Failing = 'failing';
    case Running = 'running';
    case Quiet = 'quiet';

    // Said beside the dot, so the state never rests on color alone.
    public function words(): string
    {
        return match ($this) {
            self::Failing => 'Failing',
            self::Running => 'Running',
            self::Quiet => 'Quiet',
        };
    }

    public function dot(): string
    {
        return match ($this) {
            self::Failing => 'broken',
            self::Running => 'ok',
            self::Quiet => 'idle',
        };
    }
}
