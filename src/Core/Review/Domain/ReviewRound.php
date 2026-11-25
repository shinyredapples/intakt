<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Review\Contract\FinishedState;
use Intakt\Core\Review\Contract\IdentityField;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RecordedIdentityMalformed;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Contract\VerdictStanding;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
#[ORM\Table(name: 'review_round')]
final class ReviewRound implements HasPlace
{
    // The properties are not readonly because the lazy proxy a reviewer holds for its round sets them again when it loads. The reviewers are ordered by id, the panel order the ids are handed out in, because an engine returns unordered rows as it keeps them, and PostgreSQL moves a row it updates, so repeated rounds listed their reviewers differently.
    /** @var Collection<int, RoundReviewer> */
    #[ORM\OneToMany(targetEntity: RoundReviewer::class, mappedBy: 'round', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
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

    // Kept apart from the resolved findings, because a round completed before migration Version20261112110000 kept those under its reviewer's ids, and only a null here tells such a round apart.
    #[ORM\Column(name: 'resolved_finding_ids', type: 'text', nullable: true)]
    private ?string $resolvedFindingIds = null;

    #[ORM\Column(name: 'verdicts_stale', options: ['default' => false])]
    private bool $verdictsStale = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $replay = false;

    // Null until the forge took the verdict, because a judged round whose publication was skipped, refused or is still queued is still in review for the people on the pull request.
    #[ORM\Column(name: 'published_at', nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

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

        // The head itself was refused after the round started, so no identity, however unchanged, brings its verdicts back.
        if ($this->verdictsStale) {
            return VerdictStanding::stale([IdentityField::Head]);
        }

        return VerdictIdentity::fromRecorded($this->identity)->standingAt($current, $move);
    }

    public function markVerdictsStale(): void
    {
        $this->verdictsStale = true;
    }

    // Never for a round stored before identities were recorded, because its head is not known.
    public function sharesHeadWith(self $other): bool
    {
        try {
            return $this->identity()->commits->head->hash === $other->identity()->commits->head->hash;
        } catch (RecordedIdentityMalformed) {
            return false;
        }
    }

    public function hasStaleVerdicts(): bool
    {
        return $this->verdictsStale;
    }

    public function recordPublication(DateTimeImmutable $at): void
    {
        $this->publishedAt = $at;
    }

    public function publishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function markAsReplay(): void
    {
        $this->replay = true;
    }

    public function replayMark(): ReplayMark
    {
        return $this->replay ? ReplayMark::Replay : ReplayMark::Live;
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

    public function resolvedFindingIdsJson(): ?string
    {
        return $this->resolvedFindingIds;
    }

    public function keepResolvedFindingIds(string $json): void
    {
        $this->resolvedFindingIds = $json;
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

    // A stopped reviewer ends with a verdict, so only the ledger knows it was stopped, and the caller names it.
    /**
     * @param list<string> $stopped
     */
    public function progress(array $stopped = []): RoundProgress
    {
        $finished = [];
        $started = 0;

        foreach ($this->reviewers() as $reviewer) {
            $id = $reviewer->id()->toRfc4122();

            if ($reviewer->hasStarted()) {
                ++$started;
            }

            if ($reviewer->isFinished()) {
                $finished[$id] = in_array($id, $stopped, true) ? FinishedState::Stopped : FinishedState::from($reviewer->state()->value);
            }
        }

        return new RoundProgress(count($finished), count($this->reviewers()), RoundState::Judged === $this->state, $finished, $started);
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
