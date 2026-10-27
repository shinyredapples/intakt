<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ledger_event')]
#[ORM\UniqueConstraint(name: 'ledger_event_id', columns: ['id'])]
final readonly class EventRecord
{
    // The database counts appends, because ids from a clock that steps back or from two processes in one millisecond do not sort in append order.
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private int $position;

    public function __construct(
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(length: 32, enumType: LedgerEventKind::class)]
        private LedgerEventKind $kind,
        #[ORM\Column(type: 'text')]
        private string $payload,
        #[ORM\Column(type: 'utc_datetime_immutable')]
        private DateTimeImmutable $recordedAt,
    ) {
    }

    public function id(): Uuid
    {
        return $this->id;
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
