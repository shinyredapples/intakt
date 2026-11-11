<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

interface OpenChanges
{
    // The validator of an earlier listing lets a forge answer `unchanged`, because a conditional request costs no rate limit.
    /**
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function listOpen(string $project, ?string $validator): OpenChangeListing;
}
