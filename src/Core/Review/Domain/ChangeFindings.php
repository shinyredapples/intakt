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

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private readonly string $project,
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

    public function recordFindings(string $json): void
    {
        $this->findings = $json;
    }
}
