<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use RuntimeException;

// A third worker committed a newer listing while the rows of a lost write were vouched, so those rows are no longer the newest committed listing and answering from them could release a wait the newest one holds.
final class ListingReplacedMeanwhile extends RuntimeException
{
    public static function of(string $project): self
    {
        return new self(sprintf('Another worker stored a newer listing of project "%s" while the stored rows were checked.', $project));
    }
}
