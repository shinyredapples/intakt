<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Shadow\Contract\LoopFinding;

/**
 * @template T of LoopFinding|Finding
 */
final readonly class MergedSpot
{
    /**
     * @param T $finding
     */
    private function __construct(
        public LoopFinding|Finding $finding,
        private ?int $firstLine,
        private ?int $lastLine,
    ) {
    }

    /**
     * @template F of LoopFinding|Finding
     *
     * @param F $finding
     *
     * @return self<F>
     */
    public static function of(LoopFinding|Finding $finding): self
    {
        return new self($finding, $finding->line, $finding->line);
    }

    /**
     * @param T $finding
     *
     * @return self<T>
     */
    public function joinedBy(LoopFinding|Finding $finding): self
    {
        // The whole span stays, because a later, stricter finding must not erase the line an earlier member matched on.
        $representative = $this->outranks($finding->severity) ? $this->finding : $finding;

        return new self($representative, min((int) $this->firstLine, (int) $finding->line), max((int) $this->lastLine, (int) $finding->line));
    }

    /**
     * @template O of LoopFinding|Finding
     *
     * @param self<O> $other
     */
    public function linesApart(self $other): ?int
    {
        if ($this->finding->file !== $other->finding->file || null === $this->firstLine || null === $other->firstLine) {
            return null;
        }

        return max(0, $other->firstLine - (int) $this->lastLine, $this->firstLine - (int) $other->lastLine);
    }

    private function outranks(Severity $severity): bool
    {
        return array_search($this->finding->severity, Severity::cases(), true) <= array_search($severity, Severity::cases(), true);
    }
}
