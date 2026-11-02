<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Domain;

use Doctrine\ORM\Mapping as ORM;

// The key is the head, so a second round for the same head cannot be stored, whichever worker tries it.
#[ORM\Entity]
#[ORM\Table(name: 'watcher_started_round')]
final class StartedRound
{
    #[ORM\Column(name: 'round_id', length: 36, nullable: true)]
    private ?string $roundId = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $stale = false;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private readonly string $project,
        #[ORM\Id]
        #[ORM\Column(name: 'change_ref', length: 255)]
        private readonly string $change,
        #[ORM\Id]
        #[ORM\Column(length: 64)]
        private readonly string $head,
    ) {
    }

    public function change(): string
    {
        return $this->change;
    }

    public function head(): string
    {
        return $this->head;
    }

    public function roundId(): ?string
    {
        return $this->roundId;
    }

    public function queuedAs(string $roundId): void
    {
        $this->roundId = $roundId;
    }

    public function isStale(): bool
    {
        return $this->stale;
    }

    public function markStale(): void
    {
        $this->stale = true;
    }
}
