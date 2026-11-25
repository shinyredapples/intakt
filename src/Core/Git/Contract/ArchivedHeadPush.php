<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use SensitiveParameter;

// A push of the commit and its objects, never a ref made from an id, because the forge may already have collected a commit nothing referenced.
interface ArchivedHeadPush
{
    /**
     * @throws BranchNotPushed
     */
    public function push(ArchivedHead $head, #[SensitiveParameter] string $remote, ?RemoteCredential $credential = null): void;
}
