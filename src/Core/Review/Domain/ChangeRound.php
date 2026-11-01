<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Doctrine\ORM\Mapping as ORM;

// Defaults, because rounds stored before rounds were counted per change need a value in the new columns.
#[ORM\Embeddable]
final readonly class ChangeRound
{
    public function __construct(
        #[ORM\Column(name: 'change_ref', length: 255, options: ['default' => ''])]
        public string $change,
        #[ORM\Column(name: 'round_number', options: ['default' => 1])]
        public int $number,
        // The open findings as JSON when the round opened, so every reviewer of the round is told the same ones whatever other round of the change completes meanwhile; nullable rather than defaulted, because DBAL's MySQL platform leaves a default on a text column out of the column declaration, so a declared default would not reach the MySQL schema, and a round stored before the ledger existed had none.
        #[ORM\Column(name: 'open_findings', type: 'text', nullable: true)]
        public ?string $openFindings,
    ) {
    }
}
