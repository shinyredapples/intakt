<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Verdict;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
#[ORM\Table(name: 'round_reviewer')]
#[ORM\Index(name: 'round_reviewer_round', columns: ['round_id'])]
final class RoundReviewer implements HasPlace
{
    #[ORM\Column(length: 16, enumType: ReviewerState::class)]
    private ReviewerState $state = ReviewerState::Queued;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $verdict = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $failure = null;

    public function __construct(
        // Also the id of the reviewer's conversation, so one reviewer in one round has one conversation.
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private readonly UuidV7 $id,
        #[ORM\ManyToOne(targetEntity: ReviewRound::class, inversedBy: 'reviewers')]
        #[ORM\JoinColumn(nullable: false)]
        private readonly ReviewRound $round,
        #[ORM\Column(length: 255)]
        private readonly string $roleName,
        #[ORM\Column(type: 'text')]
        private readonly string $rolePrompt,
        #[ORM\Column(length: 16, enumType: Tier::class)]
        private readonly Tier $tier,
    ) {
    }

    public function recordVerdict(Verdict $verdict): void
    {
        $this->verdict = json_encode($verdict, JSON_THROW_ON_ERROR);
    }

    public function recordFailure(string $failure): void
    {
        $this->failure = $failure;
    }

    public function id(): UuidV7
    {
        return $this->id;
    }

    public function round(): ReviewRound
    {
        return $this->round;
    }

    public function roleName(): string
    {
        return $this->roleName;
    }

    public function rolePrompt(): string
    {
        return $this->rolePrompt;
    }

    public function tier(): Tier
    {
        return $this->tier;
    }

    public function state(): ReviewerState
    {
        return $this->state;
    }

    public function isFinished(): bool
    {
        return $this->state->isFinished();
    }

    public function verdictJson(): ?string
    {
        return $this->verdict;
    }

    public function failure(): ?string
    {
        return $this->failure;
    }

    public function place(): string
    {
        return $this->state->value;
    }

    public function moveTo(string $place): void
    {
        $this->state = ReviewerState::from($place);
    }
}
