<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Doctrine\ORM\Mapping as ORM;

// The finding ledger of one change between its rounds; one row per change, so locking it orders the rounds of that change.
#[ORM\Entity]
#[ORM\Table(name: 'change_findings')]
final class ChangeFindings
{
    #[ORM\Column]
    private int $rounds = 0;

    // JSON, because a finding is a contract value that the mapping cannot embed.
    #[ORM\Column(type: 'text')]
    private string $findings = '[]';

    // Rounds of one change can overlap, and one that completes after a newer one reviewed an older head, so its verdicts must not undo the newer ones.
    #[ORM\Column(name: 'recorded_round')]
    private int $recordedRound = 0;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        public readonly string $project,
        // Named change_ref, because CHANGE is a reserved word in MySQL and MariaDB.
        #[ORM\Id]
        #[ORM\Column(name: 'change_ref', length: 255)]
        private readonly string $change,
    ) {
    }

    public function openRound(): ChangeRound
    {
        return new ChangeRound($this->change, ++$this->rounds);
    }

    public function findingsJson(): string
    {
        return $this->findings;
    }

    public function hasRecordedAfter(int $round): bool
    {
        return $this->recordedRound > $round;
    }

    public function recordFindings(int $round, string $json): void
    {
        $this->recordedRound = $round;
        $this->findings = $json;
    }
}
