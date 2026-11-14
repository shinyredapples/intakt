<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use NoDiscard;

// Booting a head runs its code on the host, so only an author with write access may have it run: docs/design/05-architecture/14-threat-model.md
interface AuthorTrust
{
    // A check that cannot answer is false, never true, so a failed read cannot let a stranger's head run.
    #[NoDiscard('an author nobody looks at may run code on the host')]
    public function isAuthorTrusted(ChangeId $change): bool;
}
