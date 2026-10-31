<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\ReviewerRole;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
#[ORM\Table(name: 'review_round')]
final class ReviewRound implements HasPlace
{
    // The properties are not readonly because the lazy proxy a reviewer holds for its round sets them again when it loads.
    /** @var Collection<int, RoundReviewer> */
    #[ORM\OneToMany(targetEntity: RoundReviewer::class, mappedBy: 'round', cascade: ['persist'])]
    private Collection $reviewers;

    #[ORM\Column(length: 16, enumType: RoundState::class)]
    private RoundState $state = RoundState::Collecting;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(length: 255)]
        private string $project,
        // The path, not a Checkout, because a worker that restarts rebuilds the checkout from the stored row.
        #[ORM\Column(type: 'text')]
        private string $checkoutRoot,
        #[ORM\Column(type: 'text')]
        private string $task,
    ) {
        $this->reviewers = new ArrayCollection();
    }

    public function addReviewer(UuidV7 $id, ReviewerRole $role, Tier $tier): void
    {
        $this->reviewers->add(new RoundReviewer($id, $this, $role->name, $role->prompt, $tier));
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function project(): string
    {
        return $this->project;
    }

    public function checkoutRoot(): string
    {
        return $this->checkoutRoot;
    }

    public function task(): string
    {
        return $this->task;
    }

    /**
     * @return list<RoundReviewer>
     */
    public function reviewers(): array
    {
        return $this->reviewers->toArray();
    }

    public function everyReviewerFinished(): bool
    {
        return array_all($this->reviewers(), static fn (RoundReviewer $reviewer): bool => $reviewer->isFinished());
    }

    public function state(): RoundState
    {
        return $this->state;
    }

    public function place(): string
    {
        return $this->state->value;
    }

    public function moveTo(string $place): void
    {
        $this->state = RoundState::from($place);
    }
}
