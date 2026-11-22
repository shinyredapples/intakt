<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Budget\Contract\OwnerNotices;
use Psr\Log\LoggerInterface;
use Throwable;
use Twig\Attribute\AsTwigFunction;

// The You item of the shell counts what the You page lists, so the badge and the page never disagree.
final readonly class WaitingCount
{
    public function __construct(
        private OwnerNotices $notices,
        private LoggerInterface $logger,
    ) {
    }

    #[AsTwigFunction('waiting_count')]
    public function now(): int
    {
        try {
            return count($this->notices->stillRunningOldestFirst());
            // Boundary: logs an unreadable notice store and shows no count, because every page carries the shell and one hint must not take them all down.
        } catch (Throwable $failure) {
            $this->logger->warning('The You item shows no count, because the owner notices could not be read.', ['exception' => $failure]);

            return 0;
        }
    }
}
