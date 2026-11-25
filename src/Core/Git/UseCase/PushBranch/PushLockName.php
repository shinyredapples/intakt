<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\PushBranch;

// One name for every push of a branch, so a build push and a restore of the same branch never run together.
final readonly class PushLockName
{
    public static function of(string $project, string $branch): string
    {
        return 'push:'.hash('sha256', json_encode([$project, $branch], JSON_THROW_ON_ERROR));
    }
}
