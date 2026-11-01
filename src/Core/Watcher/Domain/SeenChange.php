<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Plugin\Forge\Contract\OpenChange;

#[ORM\Entity]
#[ORM\Table(name: 'watcher_seen_change')]
final class SeenChange
{
    #[ORM\Column(length: 64)]
    private string $head;

    #[ORM\Column(type: 'utc_datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private readonly string $project,
        #[ORM\Id]
        #[ORM\Column(name: 'change_ref', length: 255)]
        private readonly string $change,
        OpenChange $seen,
    ) {
        $this->head = $seen->head->hash;
        $this->updatedAt = $seen->updatedAt;
    }

    public function isStillAt(OpenChange $open): bool
    {
        return $this->head === $open->head->hash && $this->updatedAt == $open->updatedAt;
    }

    public function see(OpenChange $open): void
    {
        $this->head = $open->head->hash;
        $this->updatedAt = $open->updatedAt;
    }

    public function change(): string
    {
        return $this->change;
    }
}
