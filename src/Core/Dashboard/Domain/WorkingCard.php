<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// One change somebody works on now, with the roles standing on its step; a task the build runs has no round yet, so only a review's card opens a page.
#[Exclude]
final readonly class WorkingCard
{
    // Matched by name, because a reviewer role is configured by its name and the sample names its security reviewer so (UX2 prototype: meerkat and hedgehog on Review).
    private const string SECURITY = 'security';

    /**
     * @param non-empty-list<Character> $roles
     */
    public function __construct(
        public string $title,
        public string $project,
        public WorkStep $step,
        public array $roles,
        public ?string $round,
        public ?int $toFix = null,
    ) {
    }

    // A round from before the ledger named its change has no change, so the round names it, as on the board card; a judged round is the judge's, the owl with what is left to fix.
    public static function ofReview(Review $review): self
    {
        $latest = $review->latest();
        $title = '' === $review->change ? 'Round '.$latest->round : $review->change;

        if ($latest->isJudged()) {
            return new self($title, $review->project, WorkStep::Review, [Character::Judge], $latest->round, count($review->openFindings()));
        }

        return new self($title, $review->project, WorkStep::Review, self::readersOf($latest), $latest->round);
    }

    public static function ofTask(BuildTask $task): self
    {
        return new self($task->task, $task->project, WorkStep::Build, [Character::Developer], null);
    }

    // What is left to fix once the judge has called the round, because that count is what the owner acts on (UX2 prototype: "N to fix").
    public function state(): string
    {
        return null === $this->toFix ? $this->step->doing() : $this->toFix.' to fix';
    }

    /**
     * @return non-empty-list<Character>
     */
    // The hedgehog stands beside the meerkat while the security reviewer still reads, so the card shows who is on the step now.
    private static function readersOf(ReviewedRound $round): array
    {
        foreach ($round->outcomes as $outcome) {
            if (self::SECURITY === $outcome->role && ReviewerProgress::Running === $outcome->progress) {
                return [Character::CodeReviewer, Character::SecurityEngineer];
            }
        }

        return [Character::CodeReviewer];
    }
}
