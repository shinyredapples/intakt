<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Review\Contract\IdentityField;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Contract\VerdictStanding;
use Intakt\Plugin\Forge\Contract\HeadMove;
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

    // Empty by default, because a round stored before migration Version20261031140000 has no identity.
    #[ORM\Column(name: 'verdict_identity', length: 512, options: ['default' => ''])]
    private string $identity;

    #[ORM\Embedded(class: ReviewSubject::class, columnPrefix: false)]
    private ReviewSubject $subject;

    // Kept with the round when it completes, because the change's ledger moves on with later rounds and skips the verdicts of a round that completes after a newer one; nullable rather than defaulted, because DBAL's MySQL platform leaves a default on a text column out of the column declaration, so a declared default would not reach the MySQL schema.
    #[ORM\Column(name: 'reported_findings', type: 'text', nullable: true)]
    private ?string $reportedFindings = null;

    // Kept with the round when it completes, because its indexing runs later in a worker, when a newer round may have reopened what this one resolved.
    #[ORM\Column(name: 'resolved_findings', type: 'text', nullable: true)]
    private ?string $resolvedFindings = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        ReviewTarget $target,
        #[ORM\Embedded(class: ChangeRound::class, columnPrefix: false)]
        private ChangeRound $change,
    ) {
        $this->reviewers = new ArrayCollection();
        $this->subject = $target->subject;
        $this->identity = $target->identity->recorded();
    }

    // A round without an identity cannot show what its verdicts were given on, so every field counts as changed.
    public function standingAt(VerdictIdentity $current, HeadMove $move): VerdictStanding
    {
        if ('' === $this->identity) {
            return VerdictStanding::stale(IdentityField::cases());
        }

        return VerdictIdentity::fromRecorded($this->identity)->standingAt($current, $move);
    }

    // Throws for a round stored before identities were recorded, because such a round names no head to publish on.
    public function identity(): VerdictIdentity
    {
        return VerdictIdentity::fromRecorded($this->identity);
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

    public function diff(): string
    {
        return $this->subject->diff;
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

    public function reportedFindingsJson(): ?string
    {
        return $this->reportedFindings;
    }

    public function keepReportedFindings(string $json): void
    {
        $this->reportedFindings = $json;
    }

    public function resolvedFindingsJson(): ?string
    {
        return $this->resolvedFindings;
    }

    public function keepResolvedFindings(string $json): void
    {
        $this->resolvedFindings = $json;
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

    public function progress(): RoundProgress
    {
        $finished = count(array_filter($this->reviewers(), static fn (RoundReviewer $reviewer): bool => $reviewer->isFinished()));

        return new RoundProgress($finished, count($this->reviewers()), RoundState::Judged === $this->state);
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
