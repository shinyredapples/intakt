<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use LogicException;

#[ORM\Entity]
#[ORM\Table(name: 'ledger_event')]
final class EventRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $sequence;

    public function __construct(
        #[ORM\Column(length: 32, enumType: LedgerEventKind::class)]
        private readonly LedgerEventKind $kind,
        #[ORM\Column(type: 'text')]
        private readonly string $payload,
        #[ORM\Column]
        private readonly DateTimeImmutable $recordedAt,
    ) {
    }

    public function sequence(): int
    {
        return $this->sequence ?? throw new LogicException('The event has not been stored yet.');
    }

    public function kind(): LedgerEventKind
    {
        return $this->kind;
    }

    public function payload(): string
    {
        return $this->payload;
    }

    public function recordedAt(): DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
