<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeBudget;
use Intakt\Plugin\Forge\Contract\ForgeBudgets;
use Intakt\Plugin\GitHubForge\Domain\RateLimitReading;

final readonly class GitHubForgeBudgets implements ForgeBudgets
{
    public function __construct(
        private InstallationTokens $tokens,
        private RateLimitReadings $readings,
        private BudgetReserve $reserve,
    ) {
    }

    public function thisHour(): array
    {
        $installationId = $this->tokens->installationId;

        // None while no App is set up, because an install without one makes no GitHub call.
        if ('' === $installationId) {
            return [];
        }

        return [$this->budgetOf($installationId)];
    }

    private function budgetOf(string $installationId): ForgeBudget
    {
        $reading = $this->readings->latest($installationId);

        if (!$reading instanceof RateLimitReading) {
            return new ForgeBudget($installationId, used: null, remaining: null, reserveInForce: false);
        }

        // At least 0, because a remaining above the limit (a limit raised within the hour) leaves nothing used.
        $used = max(0, $reading->limit - $reading->remaining);

        return new ForgeBudget($installationId, $used, $reading->remaining, $this->reserve->isInForce($reading));
    }
}
