<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class ChangeReview
{
    /**
     * @param list<PanelVerdict> $verdicts
     * @param list<Finding>      $actList
     * @param list<string>       $reasons
     */
    public function __construct(
        public string $roundId,
        public int $round,
        public Decision $decision,
        public array $verdicts,
        public array $actList,
        public RoundTokens $tokens,
        // Why the round is blocked, in plain words, because a blocked round has no findings to show.
        public array $reasons = [],
    ) {
    }

    // Blocked whatever the verdicts say, because they do not stand for the change: a reviewer still out could change the quorum, or the checkout changed under the reviewers.
    public function blockedFor(string $reason): self
    {
        return clone ($this, ['decision' => Decision::Blocked, 'actList' => [], 'reasons' => [$reason, ...$this->reasons]]);
    }
}
