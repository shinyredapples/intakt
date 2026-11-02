<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\MeasuredUse;
use Intakt\Core\Budget\Contract\StartDecision;
use Intakt\Core\Budget\Contract\StartGuard;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowUse;
use Override;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(StartGuard::class)]
final readonly class CeilingStartGuard implements StartGuard
{
    public const int TRUSTED_FOR_SECONDS = 1800;
    public const int PARALLEL_TRUSTED_FOR_SECONDS = 300;
    public const float PARALLEL_MARGIN = 10.0;

    /**
     * @param array<string, float> $ceilings the owner's ceiling in percent per UsageWindow value
     */
    public function __construct(
        private MeasuredUse $measured,
        private ClockInterface $clock,
        #[Autowire('%intakt.budget.ceilings%')]
        private array $ceilings,
    ) {
    }

    #[Override]
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

    /**
     * @return array<string, WindowUse>
     */
    private function currentUsesOf(Account $account): array
    {
        $uses = [];
        foreach (UsageWindow::cases() as $window) {
            $use = $this->measured->of($account, $window);
            if ($use instanceof WindowUse && !$this->hasReset($use)) {
                $uses[$window->value] = $use;
            }
        }

        return $uses;
    }

    // A window that reset since its reading starts empty, so that reading says nothing about it any more.
    private function hasReset(WindowUse $use): bool
    {
        $resetsAt = $use->usage->resetsAt;

        return $resetsAt instanceof DateTimeImmutable && $resetsAt <= $this->clock->now();
    }

    // Any age counts here, because use only grows until the reset, so an old reading at the ceiling is still at it.
    /**
     * @param array<string, WindowUse> $uses
     */
    private function reachesCeiling(array $uses, float $margin): bool
    {
        return array_any($uses, fn ($use, $window) => $use->usage->usedPercent >= $this->ceilingOf(UsageWindow::from($window), $uses, $margin));
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
