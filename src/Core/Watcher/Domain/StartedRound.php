<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Domain;

use Doctrine\ORM\Mapping as ORM;
use Intakt\Plugin\Forge\Contract\OpenChange;

// The key is the head, so a second round for the same head cannot be stored, whichever worker tries it.
#[ORM\Entity]
#[ORM\Table(name: 'watcher_started_round')]
final class StartedRound
{
    #[ORM\Id]
    #[ORM\Column(name: 'change_ref', length: 255)]
    private readonly string $change;

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private readonly string $head;

    #[ORM\Column(options: ['default' => false])]
    private bool $stale = false;

    // Set only by the statement that records the round's ledger line, in the same transaction as that line.
    #[ORM\Column(name: 'queued_recorded', options: ['default' => false])]
    private bool $queuedRecorded = false;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private readonly string $project,
        OpenChange $started,
        #[ORM\Column(name: 'round_id', length: 36)]
        private readonly string $roundId,
    ) {
        $this->change = $started->id->value;
        $this->head = $started->head->hash;
    }

    public function project(): string
    {
        return $this->project;
    }

    public function change(): string
    {
        return $this->change;
    }

    public function head(): string
    {
        return $this->head;
    }

    public function roundId(): string
    {
        return $this->roundId;
    }

    public function isStale(): bool
    {
        return $this->stale;
    }
}
