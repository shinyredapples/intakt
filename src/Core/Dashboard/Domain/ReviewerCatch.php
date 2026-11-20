<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Review\Contract\Severity;

// Kept per role, not per reviewer id, because every round gives its reviewers new ids while the role stays (D3 of #2863).
final readonly class ReviewerCatch
{
    /**
     * @param array<string, int> $found
     * @param array<string, int> $alone
     */
    public function __construct(
        public string $role,
        private array $found,
        private array $alone,
    ) {
    }

    public function found(Severity $severity): int
    {
        return $this->found[$severity->value] ?? 0;
    }

    public function alone(Severity $severity): int
    {
        return $this->alone[$severity->value] ?? 0;
    }

    public function largest(): int
    {
        return max([0, ...array_values($this->found)]);
    }

    /**
     * @return list<ChartBar>
     */
    public function bars(): array
    {
        return array_map(fn (Severity $severity): ChartBar => new ChartBar(
            $severity->value,
            $this->found($severity),
            sprintf('%d %s %s', $this->found($severity), $severity->value, 1 === $this->found($severity) ? 'finding' : 'findings'),
            sprintf('%s reviewer, %d found by it alone', ucfirst($this->role), $this->alone($severity)),
            $this->alone($severity),
        ), Severity::cases());
    }
}
