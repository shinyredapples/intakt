<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Domain;

final readonly class OwnerPushNoticePayload
{
    public function __construct(
        public string $project,
        public string $commit,
    ) {
    }
}
