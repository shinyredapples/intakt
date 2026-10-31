<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Review\Contract\PanelReviewer;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
#[ORM\Table(name: 'review_round')]
final class ReviewRound implements HasPlace
{
    // The one transition of a round; a single named value, so a constant rather than an enum.
    public const string COMPLETION = 'complete';

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
        #[ORM\Embedded(class: ReviewSubject::class, columnPrefix: false)]
        private ReviewSubject $subject,
        #[ORM\Embedded(class: ChangeRound::class, columnPrefix: false)]
        private ChangeRound $change,
    ) {
        $this->reviewers = new ArrayCollection();
    }

    public function addReviewer(UuidV7 $id, PanelReviewer $member): void
    {
        $this->reviewers->add(new RoundReviewer($id, $this, $member));
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function project(): string
    {
        return $this->subject->project;
    }

    public function checkoutRoot(): string
    {
        return $this->subject->checkoutRoot;
    }

    public function task(): string
    {
        return $this->subject->task;
    }

    public function change(): string
    {
        return $this->change->change;
    }

    public function number(): int
    {
        return $this->change->number;
    }

    public function openFindingsJson(): ?string
    {
        return $this->change->openFindings;
    }

    /**
     * @return list<RoundReviewer>
     */
    public function reviewers(): array
    {
        return array_values($this->reviewers->toArray());
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
