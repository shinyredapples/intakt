<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget;

use DateTimeImmutable;
use DateTimeInterface;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Domain\OutageNotices;
use Override;

// Records every request instead of keeping it once, so a guard test sees each outage the guard reports.
final class NoticesInMemory implements OutageNotices
{
    /** @var list<string> */
    public private(set) array $requested = [];

    #[Override]
    public function noticeOnce(Account $account, DateTimeImmutable $lastReadingAt): void
    {
        $this->requested[] = $account->key().' since '.$lastReadingAt->format(DateTimeInterface::ATOM);
    }
}
