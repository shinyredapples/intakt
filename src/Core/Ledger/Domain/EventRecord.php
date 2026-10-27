<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ledger_event')]
final class EventRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private readonly Uuid $id,
        #[ORM\Column(length: 32, enumType: LedgerEventKind::class)]
        private readonly LedgerEventKind $kind,
        #[ORM\Column(type: 'text')]
        private readonly string $payload,
        #[ORM\Column(type: 'utc_datetime_immutable')]
        private readonly DateTimeImmutable $recordedAt,
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
