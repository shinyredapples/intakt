<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Ledger\Contract\LedgerEventKind;

enum TaskStep: string
{
    case Task = 'task';
    case Mirror = 'mirror';
    case Clone = 'clone';

    public function succeeded(): LedgerEventKind
    {
        return match ($this) {
            self::Task => LedgerEventKind::TaskRead,
            self::Mirror => LedgerEventKind::MirrorUpdated,
            self::Clone => LedgerEventKind::CloneMade,
        };
    }

    public function failed(): LedgerEventKind
    {
        return match ($this) {
            self::Task => LedgerEventKind::TaskNotRead,
            self::Mirror => LedgerEventKind::MirrorNotUpdated,
            self::Clone => LedgerEventKind::CloneNotMade,
        };
    }
}
