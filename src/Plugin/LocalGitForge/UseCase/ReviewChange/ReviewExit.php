<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Review\Contract\Decision;
use Symfony\Component\Console\Command\Command;

// Apart from Command::FAILURE (1), which stays for wrong input or configuration, so a script can tell a verdict from a mistake.
enum ReviewExit: int
{
    case Approve = Command::SUCCESS;
    case Changes = 10;
    case Blocked = 11;

    public static function of(Decision $decision): self
    {
        return match ($decision) {
            Decision::Approve => self::Approve,
            Decision::Changes => self::Changes,
            Decision::Blocked => self::Blocked,
        };
    }
}
