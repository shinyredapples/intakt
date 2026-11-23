<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\PanelReviewer;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
#[ORM\Table(name: 'round_reviewer')]
#[ORM\Index(name: 'round_reviewer_round', columns: ['round_id'])]
final class RoundReviewer implements HasPlace
{
    // One hour, because a redelivery inside the hold must wait for the live worker while one after it may treat the worker as stopped; the transport's redeliver_timeout stays above it so a redelivery never arrives inside the hold.
    public const int REVIEW_CEILING_SECONDS = 3600;
    public const string REVIEW_CEILING = 'PT'.self::REVIEW_CEILING_SECONDS.'S';

    private const int FAILURE_LENGTH = 255;
    private const string CUT_MARK = '...';

    #[ORM\Column(length: 255)]
    private readonly string $roleName;

    #[ORM\Column(type: 'text')]
    private readonly string $rolePrompt;

    #[ORM\Column(length: 16, enumType: Tier::class)]
    private readonly Tier $tier;

    #[ORM\Column(length: 16, enumType: ReviewerState::class)]
    private ReviewerState $state = ReviewerState::Queued;

    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $startedAt = null;

    // How often a stopped worker's reviewer was started again; a rate limit or a held hold never counts, so they cannot use up the one retry.
    #[ORM\Column(options: ['default' => 0])]
    private int $restarts = 0;

    // New at every start, because the store refuses a conversation that was already started and a retried reviewer begins afresh; it also tells a worker whose reviewer another worker started again that the row is no longer its own.
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?UuidV7 $conversationId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $verdict = null;

    #[ORM\Column(length: self::FAILURE_LENGTH, nullable: true)]
    private ?string $failure = null;

    // The agent's own session, kept across an interruption so the review can resume where it stopped; cleared only when a new attempt follows a real failure.
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $agentSessionId = null;

    // Shown on the card only, because an outage is a wait and no limit may count it.
    #[ORM\Column(options: ['default' => 0])]
    private int $interruptions = 0;

    // The provider that reviewed in this reviewer's place; set means a catch-up review is owed, because another vendor reviewed; a move to another account of the same vendor never sets it.
    #[ORM\Column(length: 32, nullable: true, enumType: Provider::class)]
    private ?Provider $substitute = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private readonly UuidV7 $id,
        #[ORM\ManyToOne(targetEntity: ReviewRound::class, inversedBy: 'reviewers')]
        #[ORM\JoinColumn(nullable: false)]
        private readonly ReviewRound $round,
        PanelReviewer $member,
        // Kept apart from the id, because a UUIDv7 id follows the wall clock, which can step back between two reviewers of one panel.
        #[ORM\Column(name: 'panel_position', options: ['default' => 0])]
        private readonly int $position,
    ) {
        $this->roleName = $member->role->name;
        $this->rolePrompt = $member->role->prompt;
        $this->tier = $member->model->tier;
    }

    // The id breaks a tie, because every reviewer stored before migration Version20261123100000 is at position 0.
    public function panelOrder(self $other): int
    {
        return [$this->position, $this->id->toBinary()] <=> [$other->position, $other->id->toBinary()];
    }

    public function recordStart(DateTimeImmutable $now): void
    {
        $this->startedAt = $now;
        $this->conversationId = new UuidV7(UuidV7::generate($now));
    }

    public function recordRestart(): void
    {
        ++$this->restarts;
        $this->agentSessionId = null;
    }

    public function recordInterruption(): void
    {
        ++$this->interruptions;
    }

    public function recordAgentSession(string $agentSessionId): void
    {
        $this->agentSessionId = $agentSessionId;
    }

    public function recordVerdict(string $verdictJson, ?Provider $substitute): void
    {
        $this->verdict = $verdictJson;
        $this->substitute = $substitute;
    }

    // Cut, because a reason can list as many ids as the reviewer named and a longer value fails the write on MySQL and PostgreSQL, leaving the reviewer running.
    public function recordFailure(string $failure): void
    {
        if (strlen($failure) <= self::FAILURE_LENGTH) {
            $this->failure = $failure;

            return;
        }

        $this->failure = mb_strcut($failure, 0, self::FAILURE_LENGTH - strlen(self::CUT_MARK), 'UTF-8').self::CUT_MARK;
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

    public function hasStarted(): bool
    {
        return $this->state->hasStarted();
    }

    public function isFinished(): bool
    {
        return $this->state->isFinished();
    }

    // Null unless running, because only a reviewer a worker is reviewing is held by one.
    public function heldUntil(): ?DateTimeImmutable
    {
        return ReviewerState::Running === $this->state ? $this->startedAt?->add(new DateInterval(self::REVIEW_CEILING)) : null;
    }

    public function agentSessionId(): ?string
    {
        return $this->agentSessionId;
    }

    public function interruptions(): int
    {
        return $this->interruptions;
    }

    public function hasRestarted(): bool
    {
        return $this->restarts > 0;
    }

    public function isSameAttemptAs(self $taken): bool
    {
        return $this->conversationId?->equals($taken->conversationId) ?? false;
    }

    /**
     * @throws ReviewerNotStarted
     */
    public function conversationId(): UuidV7
    {
        return $this->conversationId ?? throw ReviewerNotStarted::for($this->id->toRfc4122());
    }

    public function verdictJson(): ?string
    {
        return $this->verdict;
    }

    public function substitute(): ?Provider
    {
        return $this->substitute;
    }

    public function owesCatchUp(): bool
    {
        return $this->substitute instanceof Provider;
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
