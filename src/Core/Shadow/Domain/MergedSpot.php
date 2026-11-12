<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Domain;

use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Shadow\Contract\LoopFinding;

/**
 * @template T of LoopFinding
 */
final readonly class MergedSpot
{
    /**
     * @param T                       $finding
     * @param non-empty-array<int, T> $members
     */
    private function __construct(
        public LoopFinding $finding,
        private array $members,
    ) {
    }

    /**
     * @template F of LoopFinding
     *
     * @param F $finding
     *
     * @return self<F>
     */
    public static function of(LoopFinding $finding, int $review): self
    {
        return new self($finding, [$review => $finding]);
    }

    /**
     * @param T $finding
     */
    public function admits(LoopFinding $finding, int $review): bool
    {
        // One review's findings are separate points by its own account, and a spot no wider than the window keeps a chain of nearby findings from collapsing into one.
        if (array_key_exists($review, $this->members) || !$this->sameFile($finding)) {
            return false;
        }

        $lines = [...$this->lines(), $finding->line];

        return max($lines) - min($lines) <= ReviewComparison::LINE_WINDOW;
    }

    /**
     * @param T $finding
     *
     * @return self<T>
     */
    public function joinedBy(LoopFinding $finding, int $review): self
    {
        // Every member's line stays, because a later, stricter finding must not erase the line an earlier member matched on.
        $representative = $this->outranks($finding->severity) ? $this->finding : $finding;

        return new self($representative, $this->members + [$review => $finding]);
    }

    /**
     * @template O of LoopFinding
     *
     * @param self<O> $other
     */
    public function linesApart(self $other): ?int
    {
        if (!$this->sameFile($other->finding)) {
            return null;
        }

        return max(0, min($other->lines()) - max($this->lines()), min($this->lines()) - max($other->lines()));
    }

    private function sameFile(LoopFinding $finding): bool
    {
        return $finding->file === $this->finding->file;
    }

    /**
     * @return non-empty-list<int>
     */
    private function lines(): array
    {
        return array_values(array_map(static fn (LoopFinding $member): int => $member->line, $this->members));
    }

    private function outranks(Severity $severity): bool
    {
        return array_search($this->finding->severity, Severity::cases(), true) <= array_search($severity, Severity::cases(), true);
    }
}
