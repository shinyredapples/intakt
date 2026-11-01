<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Doctrine\ORM\Mapping as ORM;

// The finding ledger of one change between its rounds; one row per change, so locking it orders the rounds of that change.
#[ORM\Entity]
#[ORM\Table(name: 'change_findings')]
final class ChangeFindings
{
    // Read by the raw insert of a change's first row too, so the entity and the insert start alike.
    public const int NO_ROUNDS = 0;
    public const string NO_FINDINGS = '[]';

    #[ORM\Column]
    private int $rounds = self::NO_ROUNDS;

    // JSON, because a finding is a contract value that the mapping cannot embed.
    #[ORM\Column(type: 'text')]
    private string $findings = self::NO_FINDINGS;

    // Rounds of one change can overlap, and one that completes after a newer one reviewed an older head, so its verdicts must not undo the newer ones.
    #[ORM\Column(name: 'recorded_round')]
    private int $recordedRound = self::NO_ROUNDS;

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

    public function openRound(string $openFindingsJson): ChangeRound
    {
        return new ChangeRound($this->change, ++$this->rounds, $openFindingsJson);
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
