<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Says how the forge got to the change, because only a change Intakt's identity created is handed to Intakt, while an update may touch a change someone else opened on the same branch.
final readonly class OpenedChange
{
    public function __construct(
        public ChangeId $id,
        public Opening $opening,
    ) {
    }
}
