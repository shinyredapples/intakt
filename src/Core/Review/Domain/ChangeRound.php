<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Doctrine\ORM\Mapping as ORM;

// The defaults only fill the rounds stored before rounds were counted per change.
#[ORM\Embeddable]
final readonly class ChangeRound
{
    public function __construct(
        #[ORM\Column(name: 'change_ref', length: 255, options: ['default' => ''])]
        public string $change,
        #[ORM\Column(name: 'round_number', options: ['default' => 1])]
        public int $number,
    ) {
    }
}
