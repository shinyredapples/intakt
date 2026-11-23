<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use Symfony\Component\Process\Exception\RuntimeException;

// A Process RuntimeException, because every caller of a git call already handles one; its own type lets a caller tell this refusal from a call that was killed or timed out.
final class WorkTreeInBareRepository extends RuntimeException
{
    public static function at(string $repository): self
    {
        return new self(sprintf('%s names a work tree in its config (core.worktree), so a bare call refuses it', $repository));
    }
}
