<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final readonly class ReviewSubject
{
    public function __construct(
        #[ORM\Column(length: 255)]
        public string $project,
        // The path, not a Checkout, because a worker that restarts rebuilds the checkout from the stored row.
        #[ORM\Column(type: 'text')]
        public string $checkoutRoot,
        #[ORM\Column(type: 'text')]
        public string $task,
        // Stored with the round, because a worker that restarts rebuilds the briefing from the row; empty by default for rounds stored before it, and not nullable because MySQL refuses a default on a text column otherwise.
        #[ORM\Column(type: 'text', options: ['default' => ''])]
        public string $diff,
    ) {
    }
}
