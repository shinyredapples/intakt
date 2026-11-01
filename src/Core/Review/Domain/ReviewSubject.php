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
        // Stored with the round, because a worker that restarts rebuilds the briefing from the row; not nullable, because a missing diff means the same as an empty one; defaulted to '', because SQLite refuses to add a not-null column without a default and PostgreSQL fills the rows stored before it only from one (MySQL, where DBAL leaves the default out, fills them with its implicit '').
        #[ORM\Column(type: 'text', options: ['default' => ''])]
        public string $diff,
    ) {
    }
}
