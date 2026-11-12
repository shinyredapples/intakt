<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Doctrine\ORM\Mapping as ORM;

// One row per call rather than counters on the round, because the reviewers of a round call tools at the same time and an insert never overwrites another's.
#[ORM\Entity]
#[ORM\Table(name: 'round_tool_call')]
#[ORM\Index(name: 'round_tool_call_round', columns: ['round_id'])]
final class RoundToolCall
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    public private(set) int $position;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: ReviewRound::class)]
        #[ORM\JoinColumn(nullable: false)]
        public readonly ReviewRound $round,
        #[ORM\Column(length: 64)]
        public readonly string $tool,
        // Set only when the server answered with the file, because a refused path was never read.
        #[ORM\Column(name: 'file_read', type: 'text', nullable: true)]
        public readonly ?string $fileRead,
    ) {
    }
}
