<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Counted over the judged rounds that recorded every finding the judge saw and marked, all of them readable, and that recorded the judge's own marks (not the fallback for an answer that cannot be read, nor plain code's act marks), because a round kept only as the judge's marks, or one with an unmarked P3, cannot tell a rejected finding from one never recorded or never judged.
#[Exclude]
final readonly class JudgeOutcomes
{
    private function __construct(
        public int $kept,
        public int $merged,
        public int $deferred,
        public int $rejected,
    ) {
    }

    /**
     * @param list<ReviewedRound> $rounds
     */
    public static function of(array $rounds): ?self
    {
        $counted = array_values(array_filter($rounds, static fn (ReviewedRound $round): bool => $round->judgedAt instanceof DateTimeImmutable && $round->findingTextRecorded && $round->everyFindingRecorded && false === $round->judgeFellBack));

        if ([] === $counted) {
            return null;
        }

        return new self(
            self::called(JudgeCall::Act, $counted),
            array_sum(array_map(self::mergedIn(...), $counted)),
            self::called(JudgeCall::FollowUp, $counted),
            self::called(JudgeCall::NotActedOn, $counted),
        );
    }

    public function total(): int
    {
        return $this->kept + $this->merged + $this->deferred + $this->rejected;
    }

    public function takeaway(): string
    {
        if (0 === $this->total()) {
            return 'The judge had no findings to judge yet.';
        }

        return sprintf('The judge kept %d of %d findings and rejected %d.', $this->kept, $this->total(), $this->rejected);
    }

    public function scaleTop(): int
    {
        return ChartScale::topFor(max($this->kept, $this->merged, $this->deferred, $this->rejected));
    }

    /**
     * @return list<ChartBar>
     */
    public function bars(): array
    {
        return [
            $this->bar('To fix', $this->kept, 'kept to fix'),
            $this->bar('Merged', $this->merged, 'merged as duplicate'),
            $this->bar('Deferred', $this->deferred, 'deferred to a follow-up issue'),
            $this->bar('Rejected', $this->rejected, 'rejected'),
        ];
    }

    /**
     * @param list<ReviewedRound> $rounds
     */
    private static function called(JudgeCall $call, array $rounds): int
    {
        return array_sum(array_map(static fn (ReviewedRound $round): int => count(array_filter($round->findings, static fn (RoundFinding $finding): bool => $call === $finding->call)), $rounds));
    }

    // Each merged finding counts once, because the judge may name one finding in the merges of two entries.
    private static function mergedIn(ReviewedRound $round): int
    {
        return count(array_unique(array_merge([], ...array_map(static fn (RoundFinding $finding): array => $finding->merged, $round->findings))));
    }

    private function bar(string $label, int $value, string $outcome): ChartBar
    {
        return new ChartBar($label, $value, sprintf('%d %s', $value, 1 === $value ? 'finding' : 'findings'), ucfirst($outcome));
    }
}
