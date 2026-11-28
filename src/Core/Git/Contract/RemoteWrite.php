<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

// What a failed push did to the remote, so its caller looks for refs the push may have left only after a write and never reads that from the message: None when it was refused before git push ran, Created when git push reported the new branch, Unknown when git push ran without reporting it.
enum RemoteWrite
{
    case None;
    case Unknown;
    case Created;
}
