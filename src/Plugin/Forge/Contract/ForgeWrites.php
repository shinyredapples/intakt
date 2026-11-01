<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Apart from the reads, because a read-only forge (the local git one) has nothing to write and a shadow project must reach none of these.
interface ForgeWrites
{
    public function postReview(string $project, ChangeId $change, string $body): void;
}
