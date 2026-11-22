<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Budget\Contract\OwnerNotices;
use Twig\Attribute\AsTwigFunction;

// The You item of the shell counts what the You page lists, so the badge and the page never disagree.
final readonly class WaitingCount
{
    public function __construct(
        private OwnerNotices $notices,
    ) {
    }

    #[AsTwigFunction('waiting_count')]
    public function now(): int
    {
        return count($this->notices->stillRunningOldestFirst());
    }
}
