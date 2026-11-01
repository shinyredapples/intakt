<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

interface OpenChanges
{
    /**
     * Lists the open changes of a project; given the validator of an earlier listing, a forge that supports conditional requests answers `unchanged` at no cost to its rate limit.
     *
     * @throws ForgeReadFailed
     */
    public function listOpen(string $project, ?string $validator): OpenChangeListing;
}
