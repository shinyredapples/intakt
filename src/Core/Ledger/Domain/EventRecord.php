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
#[ORM\Index(name: 'ledger_event_subject', columns: ['kind', 'subject', 'position'])]
final class EventRecord
{
    // The database counts appends, because ids from a clock that steps back or from two processes in one millisecond do not sort in append order.
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    public private(set) int $position;

    public function __construct(
        #[ORM\Column(type: 'uuid')]
        private readonly Uuid $id,
        #[ORM\Column(length: 32)]
        private readonly string $kind,
        #[ORM\Column(type: 'text')]
        private readonly string $payload,
        #[ORM\Column(type: 'utc_datetime_immutable')]
        private readonly DateTimeImmutable $recordedAt,
        #[ORM\Column(length: 255, nullable: true)]
        private readonly ?string $subject = null,
    ) {
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    // Stored as text so a row of a kind this code no longer knows still loads; the read skips it.
    public function kind(): ?LedgerEventKind
    {
        return LedgerEventKind::tryFrom($this->kind);
    }

    public function payload(): string
    {
        return $this->payload;
    }

    public function recordedAt(): DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function subject(): ?string
    {
        return $this->subject;
    }
}
