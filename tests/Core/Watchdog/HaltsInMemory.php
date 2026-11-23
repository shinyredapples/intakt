<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog;

use DateTimeImmutable;
use Intakt\Core\Watchdog\Contract\Halts;
use Intakt\Core\Watchdog\Contract\MainViolation;
use Intakt\Core\Watchdog\Contract\OwnerCard;
use Intakt\Core\Watchdog\Contract\Violation;
use Override;
use Symfony\Component\Uid\Uuid;

final class HaltsInMemory implements Halts
{
    /** @var list<OwnerCard> */
    private array $standing = [];

    #[Override]
    public function standing(): array
    {
        return $this->standing;
    }

    public function halt(string $project): void
    {
        $this->standing[] = new OwnerCard(Uuid::v7(), $project, new MainViolation(str_repeat('a', 40), Violation::Unsigned), new DateTimeImmutable('2026-11-02T08:00:00Z'));
    }

    public function clearAll(): void
    {
        $this->standing = [];
    }
}
