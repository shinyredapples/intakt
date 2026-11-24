<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Review\Contract\Labels;
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

    // Kept on the round, because the round commits before its ledger line is appended, so the line stays owed until the ledger takes it.
    #[ORM\Column(name: 'queued_recorded', options: ['default' => false])]
    private bool $queuedRecorded = false;

    // Kept on the round, because only Review withdraws an approval already on the forge, and a head's next boot must not forget a withdrawal Review did not take.
    #[ORM\Column(name: 'withdrawal_owed', options: ['default' => false])]
    private bool $withdrawalOwed = false;

    // Apart from the stale flag, because a move may have found the round stale before its head returned and failed to boot, and that approval is still on the forge.
    #[ORM\Column(name: 'withdrawal_requested', options: ['default' => false])]
    private bool $withdrawalRequested = false;

    // Kept on the round, because its verdicts are judged by the labels it started with.
    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private readonly array $labels;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private readonly string $project,
        OpenChange $started,
        #[ORM\Column(name: 'round_id', length: 36)]
        private readonly string $roundId,
        Labels $labels = new Labels(),
    ) {
        $this->change = $started->id->value;
        $this->head = $started->head->hash;
        $this->labels = [...$labels];
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

    public function labels(): Labels
    {
        return new Labels(...$this->labels);
    }

    public function isStale(): bool
    {
        return $this->stale;
    }

    public function owesQueuedLine(): bool
    {
        return !$this->queuedRecorded;
    }

    public function owesWithdrawal(): bool
    {
        return $this->withdrawalOwed;
    }

    public function withdrawalWasRequested(): bool
    {
        return $this->withdrawalRequested;
    }
}
