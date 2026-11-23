<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;

#[ORM\Entity]
#[ORM\Table(name: 'budget_outage_notice')]
#[ORM\UniqueConstraint(name: 'budget_outage_notice_outage', columns: ['provider', 'account', 'last_reading_at'])]
final class OutageNoticeRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    public private(set) int $position;

    #[ORM\Column(length: 32, enumType: Provider::class)]
    public readonly Provider $provider;

    #[ORM\Column(length: 255)]
    public readonly string $account;

    // Kept on the notice, because only the latest reading of a window is stored, so the reading that ended the outage is gone once the next one arrives.
    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    public private(set) ?DateTimeImmutable $endedAt = null;

    public function __construct(
        Account $account,
        #[ORM\Column(type: 'utc_datetime_immutable')]
        public readonly DateTimeImmutable $lastReadingAt,
        #[ORM\Column(type: 'utc_datetime_immutable')]
        public readonly DateTimeImmutable $noticedAt,
    ) {
        $this->provider = $account->provider;
        $this->account = $account->name;
    }
}
