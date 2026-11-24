<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

enum SignatureStatus: string
{
    case OwnerSigned = 'owner_signed';
    case PeerSigned = 'peer_signed';
    case Unsigned = 'unsigned';
    case UnknownKey = 'unknown_key';
}
