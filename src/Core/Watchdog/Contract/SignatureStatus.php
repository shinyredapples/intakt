<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

enum SignatureStatus: string
{
    case Good = 'good';
    case Unsigned = 'unsigned';
    case UnknownKey = 'unknown_key';
}
