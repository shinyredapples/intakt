<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\MeasuredUse;
use Intakt\Core\Budget\Contract\ReadingOutcome;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Budget\Contract\WindowUse;
use Override;
use Symfony\Component\Clock\ClockInterface;

// Keeps every reading as given, so a test can place one the Doctrine store would refuse, such as one whose reset has since passed.
final class ReadingsInMemory implements MeasuredUse
{
    /** @var array<string, array<string, WindowUse>> */
    private array $readings = [];

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    #[Override]
    public function record(WindowReading $reading): ReadingOutcome
    {
        $this->readings[$reading->account->key()][$reading->window->value] = new WindowUse($reading->usage, $this->clock->now(), 0);

        return ReadingOutcome::Recorded;
    }

    #[Override]
    public function of(Account $account, UsageWindow $window): ?WindowUse
    {
        $kept = $this->readings[$account->key()][$window->value] ?? null;
        if (null === $kept) {
            return null;
        }

        return new WindowUse($kept->usage, $kept->takenAt, $this->clock->now()->getTimestamp() - $kept->takenAt->getTimestamp());
    }
}
