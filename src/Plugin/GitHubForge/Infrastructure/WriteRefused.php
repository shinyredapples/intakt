<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use RuntimeException;

// A write GitHub certainly did not store (nothing was sent, or GitHub answered 4xx), apart from a write whose outcome is unknown, because only the first may be sent again.
final class WriteRefused extends RuntimeException
{
    public function __construct(public readonly ForgeWriteFailed|ForgeRateLimited $failure)
    {
        parent::__construct($failure->getMessage(), previous: $failure);
    }
}
