<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use NoDiscard;

interface MirrorFolders
{
    // Only the folders a mirror update fetches into, because a folder in the mirrors folder that is no valid mirror is never written to: its update goes to the mirrors folder itself.
    /**
     * @return list<string>
     */
    #[NoDiscard]
    public function existing(): array;
}
