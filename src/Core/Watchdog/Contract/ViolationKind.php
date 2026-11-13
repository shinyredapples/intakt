<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

enum ViolationKind: string
{
    case Unsigned = 'unsigned';
    case UnknownKey = 'unknown_key';
    case HistoryRewritten = 'history_rewritten';
}
