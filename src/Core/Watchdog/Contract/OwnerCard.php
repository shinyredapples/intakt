<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

// The card is the halt's ledger event, so its id is that event's id and only an owner-cleared event that names it lifts it.
final readonly class OwnerCard
{
    public function __construct(
        public Uuid $id,
        public string $project,
        public ?MainViolation $violation,
        public DateTimeImmutable $raisedAt,
    ) {
    }

    // A halt row that no longer reads still halts, because a watchdog that lost the reason of a halt must not call the project clear.
    public static function unreadable(Uuid $id, DateTimeImmutable $raisedAt): self
    {
        return new self($id, '', null, $raisedAt);
    }

    public function isReadable(): bool
    {
        return $this->violation instanceof MainViolation;
    }

    // An unreadable card names no project, so it halts every one.
    public function halts(string $project): bool
    {
        return !$this->isReadable() || $this->project === $project;
    }
}
