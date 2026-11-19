<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class OwnerNotice
{
    public function __construct(
        public Account $account,
        public DateTimeImmutable $lastReadingAt,
        public DateTimeImmutable $noticedAt,
    ) {
    }
}
