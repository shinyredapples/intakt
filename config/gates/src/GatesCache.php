<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class GatesCache
{
    public const string VARIABLE = 'INTAKT_GATES_CACHE';

    public static function directory(): string
    {
        $named = getenv(self::VARIABLE);

        return is_string($named) && '' !== $named ? $named : sys_get_temp_dir().'/intakt-gates';
    }
}
