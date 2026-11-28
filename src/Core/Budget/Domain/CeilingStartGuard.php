<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\MeasuredUse;
use Intakt\Core\Budget\Contract\StartDecision;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowUse;
use Symfony\Component\Clock\ClockInterface;

// Not the StartGuard itself, because BudgetGuard asks it only once no hold of the account or the install refuses the start.
final readonly class CeilingStartGuard
{
    public const int TRUSTED_FOR_SECONDS = 1800;
    public const int PARALLEL_TRUSTED_FOR_SECONDS = 300;
    public const float PARALLEL_MARGIN = 10.0;

    /**
     * @param array<string, float> $ceilings
     */
    public function __construct(
        private MeasuredUse $measured,
        private ClockInterface $clock,
        private array $ceilings,
    ) {
    }

    public function decide(Account $account, int $callsRunning): StartDecision
    {
        $uses = $this->currentUsesOf($account);

        return match (true) {
            $this->reachesCeiling($uses, 0.0) => StartDecision::AtCeiling,
            0 === $callsRunning => StartDecision::Start,
            !$this->everyWindowReadWithin($uses, self::TRUSTED_FOR_SECONDS) => StartDecision::NoTrustedReading,
            !$this->everyWindowReadWithin($uses, self::PARALLEL_TRUSTED_FOR_SECONDS) => StartDecision::ReadingNotFreshEnough,
            $this->reachesCeiling($uses, self::PARALLEL_MARGIN) => StartDecision::NoMarginForParallelStart,
            default => StartDecision::Start,
        };
    }

    // A reading older than the probe interval counts as unknown, and so does an account without a current reading; unlike decide(), an old reading at the ceiling leaves no account out, because an unknown account must stay eligible when no trusted one has room.
    public function roomOf(Account $account): AccountRoom
    {
        $uses = $this->currentUsesOf($account);
        $fresh = array_filter($uses, static fn (WindowUse $use): bool => $use->ageInSeconds <= self::TRUSTED_FOR_SECONDS);
        $trusted = [] !== $uses && count($fresh) === count($uses);
        $holding = $this->lastToStopHolding($fresh);
        if ($holding instanceof UsageWindow) {
            return new AccountRoom($holding, $this->holdsUntil($holding, $fresh), $trusted);
        }

        $known = array_filter(array_map(static fn (WindowUse $use): ?DateTimeImmutable => $use->usage->resetsAt, $uses), static fn (?DateTimeImmutable $resetsAt): bool => $resetsAt instanceof DateTimeImmutable);
        usort($known, static fn (DateTimeImmutable $one, DateTimeImmutable $other): int => $one <=> $other);

        return new AccountRoom(null, array_first($known), $trusted);
    }

    /**
     * @return array<string, WindowUse>
     */
    private function currentUsesOf(Account $account): array
    {
        $uses = [];
        foreach (UsageWindow::cases() as $window) {
            $use = $this->measured->of($account, $window);
            if ($use instanceof WindowUse && !$this->hasReset($use, $window)) {
                $uses[$window->value] = $use;
            }
        }

        return $uses;
    }

    // With both windows at their ceiling the account is free only once the last of them stops holding it, and a window whose end is unknown holds it longest; the weekly window comes first, so it is the cause named when both end together.
    /**
     * @param array<string, WindowUse> $uses
     */
    private function lastToStopHolding(array $uses): ?UsageWindow
    {
        $atCeiling = array_filter([UsageWindow::Weekly, UsageWindow::FiveHour], fn (UsageWindow $window): bool => isset($uses[$window->value]) && $uses[$window->value]->usage->usedPercent >= $this->ceilingOf($window, $uses, 0.0));
        usort($atCeiling, fn (UsageWindow $one, UsageWindow $other): int => ($this->holdsUntil($other, $uses)?->getTimestamp() ?? PHP_INT_MAX) <=> ($this->holdsUntil($one, $uses)?->getTimestamp() ?? PHP_INT_MAX));

        return array_first($atCeiling);
    }

    // A short window below its configured ceiling is held only by the weekly pace, so it frees at the first of its own reset, the weekly reset that ends the week's reading, and the moment the rising pace lifts its ceiling above its use.
    /**
     * @param array<string, WindowUse> $uses
     */
    private function holdsUntil(UsageWindow $window, array $uses): ?DateTimeImmutable
    {
        $use = $uses[$window->value];
        $resetsAt = $use->usage->resetsAt;
        $weekly = $uses[UsageWindow::Weekly->value] ?? null;
        $weekResetsAt = $weekly?->usage->resetsAt;
        if (UsageWindow::Weekly === $window || !$weekly instanceof WindowUse || $use->usage->usedPercent >= $this->ceilings[$window->value] || !$weekResetsAt instanceof DateTimeImmutable) {
            return $resetsAt;
        }

        $ends = array_filter([$resetsAt, $weekResetsAt, $this->paceReleases($use, $weekly, $weekResetsAt)], static fn (?DateTimeImmutable $end): bool => $end instanceof DateTimeImmutable);

        return min($ends);
    }

    // The readings stand still until the next probe, so only the elapsed week moves the paced ceiling; the account is free the first whole second the ceiling passes the use, because a use at the ceiling still holds it.
    private function paceReleases(WindowUse $short, WindowUse $weekly, DateTimeImmutable $weekResetsAt): ?DateTimeImmutable
    {
        $weeklyCeiling = $this->ceilings[UsageWindow::Weekly->value];
        if ($weeklyCeiling <= 0.0) {
            return null;
        }

        $neededShare = $short->usage->usedPercent * $weekly->usage->usedPercent / $this->ceilings[UsageWindow::FiveHour->value] / $weeklyCeiling;
        if ($neededShare >= 1.0) {
            return null;
        }

        $week = UsageWindow::Weekly->seconds();
        $now = $this->clock->now();
        $elapsed = $week - ($weekResetsAt->getTimestamp() - $now->getTimestamp());
        $seconds = (int) floor($neededShare * $week - $elapsed - UsageWindow::FiveHour->seconds()) + 1;

        return $now->modify(sprintf('+%d seconds', max(1, $seconds)));
    }

    // A reset window starts empty, so its reading says nothing any more; without a reset time, one window's length is when it reset at the latest.
    private function hasReset(WindowUse $use, UsageWindow $window): bool
    {
        $resetsAt = $use->usage->resetsAt;
        if (!$resetsAt instanceof DateTimeImmutable) {
            return $use->ageInSeconds >= $window->seconds();
        }

        return $resetsAt <= $this->clock->now();
    }

    // Any age short of the reset counts here, because use only grows until the reset, so an old reading at the ceiling is still at it.
    /**
     * @param array<string, WindowUse> $uses
     */
    private function reachesCeiling(array $uses, float $margin): bool
    {
        return $this->windowAtCeiling($uses, $margin) instanceof UsageWindow;
    }

    /**
     * @param array<string, WindowUse> $uses
     */
    private function windowAtCeiling(array $uses, float $margin): ?UsageWindow
    {
        return array_find([UsageWindow::Weekly, UsageWindow::FiveHour], fn (UsageWindow $window): bool => isset($uses[$window->value]) && $uses[$window->value]->usage->usedPercent >= $this->ceilingOf($window, $uses, $margin));
    }

    /**
     * @param array<string, WindowUse> $uses
     */
    private function ceilingOf(UsageWindow $window, array $uses, float $margin): float
    {
        return $this->ceilings[$window->value]
            |> (fn (float $ceiling): float => UsageWindow::FiveHour === $window ? $this->pacedByWeek($ceiling, $uses[UsageWindow::Weekly->value] ?? null) : $ceiling)
            |> (static fn (float $ceiling): float => $ceiling - $margin);
    }

    // Ahead of the weekly pace the short window gets the share of its ceiling the pace allows, so the week is not spent by day three.
    private function pacedByWeek(float $ceiling, ?WindowUse $weekly): float
    {
        $pace = $this->weeklyPace($weekly);
        if (null === $pace || !$weekly instanceof WindowUse || $weekly->usage->usedPercent <= $pace) {
            return $ceiling;
        }

        return $ceiling * $pace / $weekly->usage->usedPercent;
    }

    // The pace counts the current five-hour window as already spent, so a week that just reset is not throttled by its first calls.
    private function weeklyPace(?WindowUse $weekly): ?float
    {
        $resetsAt = $weekly?->usage->resetsAt;
        if (!$resetsAt instanceof DateTimeImmutable) {
            return null;
        }

        $week = UsageWindow::Weekly->seconds();
        $elapsed = $week - ($resetsAt->getTimestamp() - $this->clock->now()->getTimestamp());

        return $this->ceilings[UsageWindow::Weekly->value] * min(1.0, max(0, $elapsed + UsageWindow::FiveHour->seconds()) / $week);
    }

    /**
     * @param array<string, WindowUse> $uses
     */
    private function everyWindowReadWithin(array $uses, int $seconds): bool
    {
        foreach (UsageWindow::cases() as $window) {
            $use = $uses[$window->value] ?? null;
            if (null === $use || $use->ageInSeconds > $seconds) {
                return false;
            }
        }

        return true;
    }
}
