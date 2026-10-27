<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use UnexpectedValueException;

#[ORM\Entity]
#[ORM\Table(name: 'ledger_event')]
final class EventRecord
{
    private const string WALL_CLOCK = 'Y-m-d H:i:s.u';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) int $sequence;

    public function __construct(
        #[ORM\Column(length: 32, enumType: LedgerEventKind::class)]
        private readonly LedgerEventKind $kind,
        #[ORM\Column(type: 'text')]
        private readonly string $payload,
        #[ORM\Column]
        private readonly DateTimeImmutable $recordedAt,
    ) {
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
        // The column keeps the UTC wall clock without its offset, and Doctrine reads it back in PHP's default time zone.
        $wallClock = $this->recordedAt->format(self::WALL_CLOCK);

        $recordedAt = DateTimeImmutable::createFromFormat(self::WALL_CLOCK, $wallClock, new DateTimeZone('UTC'));

        if (false === $recordedAt) {
            throw new UnexpectedValueException('the stored moment is not a wall clock: '.$wallClock);
        }

        return $recordedAt;
    }
}
