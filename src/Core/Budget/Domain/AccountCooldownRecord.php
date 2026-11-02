<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;

#[ORM\Entity]
#[ORM\Table(name: 'budget_account_cooldown')]
#[ORM\Index(name: 'budget_account_cooldown_account', columns: ['provider', 'account'])]
final class AccountCooldownRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    public private(set) int $position;

    #[ORM\Column(length: 32, enumType: Provider::class)]
    public readonly Provider $provider;

    #[ORM\Column(length: 255)]
    public readonly string $account;

    public function __construct(
        Account $account,
        #[ORM\Column(type: 'utc_datetime_immutable')]
        public readonly DateTimeImmutable $until,
        #[ORM\Column(type: 'utc_datetime_immutable')]
        public readonly DateTimeImmutable $placedAt,
    ) {
        $this->provider = $account->provider;
        $this->account = $account->name;
    }
}
