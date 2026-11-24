<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Contract\Prices;
use Intakt\Core\Dashboard\Domain\TeamSpend;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenUsageRow;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageSettled;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageStarted;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageUnsettled;
use Symfony\Component\Clock\ClockInterface;

// Days and weeks in UTC, because the ledger records UTC times; priced first by the model a call's start named, so an account's spend agrees with a round's cost.
final readonly class LedgerAccountSpend
{
    public function __construct(
        private Ledger $ledger,
        private RoundPayloads $payloads,
        private Prices $prices,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, TeamSpend>
     */
    public function ofEachAccount(): array
    {
        $today = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
        $spend = [];

        foreach ($this->costsSince($today->modify('monday this week')) as [$account, $cost, $at]) {
            $sum = $spend[$account] ?? TeamSpend::none();
            $spend[$account] = new TeamSpend($at >= $today ? $sum->today->plus($cost) : $sum->today, $sum->thisWeek->plus($cost));
        }

        return $spend;
    }

    // Starts are read from the beginning, because a call that ends this week may have started before it.
    /**
     * @return list<array{string, Cost, DateTimeImmutable}>
     */
    private function costsSince(DateTimeImmutable $since): array
    {
        $models = [];
        $costs = [];

        foreach ($this->ledger->readOfKind(LedgerEventKind::UsageRecorded) as $row) {
            $usage = $this->payloads->usageOf($row);
            if ($usage instanceof UsageStarted) {
                $models[$usage->call] = $usage->model;
            }

            if (($usage instanceof UsageSettled || $usage instanceof UsageUnsettled) && $row->recordedAt >= $since) {
                array_push($costs, ...$this->costsOfEnding($usage, $models[$usage->call] ?? null, $row->recordedAt));
            }
        }

        return $costs;
    }

    /**
     * @return list<array{string, Cost, DateTimeImmutable}>
     */
    private function costsOfEnding(UsageSettled|UsageUnsettled $ended, ?string $model, DateTimeImmutable $at): array
    {
        $costs = [];

        foreach ($ended->usages as $usage) {
            if (null !== $usage->account) {
                $costs[] = [$usage->account, $this->costOf($model ?? $usage->model, $usage), $at];
            }
        }

        return $costs;
    }

    // A usage without input or output tokens makes the cost unknown, because the other count alone would read as the whole cost.
    private function costOf(?string $model, TokenUsageRow $usage): Cost
    {
        $cost = $this->prices->costOf($model, $usage->figures());

        return null === $usage->prompt || null === $usage->completion ? $cost->plus(Cost::unknown()) : $cost;
    }
}
