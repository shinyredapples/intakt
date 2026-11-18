<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\DroppedFinding;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\RoundJudged;
use Intakt\Core\Review\Domain\CausesInOneHunk;
use Intakt\Core\Review\Domain\QuotesInChange;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
use Intakt\Plugin\Vendor\Contract\VendorAccountOut;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

// Runs after the round's completing transaction, never inside it, because the judge call must not hold the round's lock.
#[AsCommandHandler]
final readonly class JudgeRoundHandler
{
    public function __construct(
        private JudgedRounds $rounds,
        private Vendor $vendor,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(JudgeRound $command): void
    {
        $round = $this->rounds->toJudge($command->roundId);

        if (!$round instanceof ReviewRound) {
            return;
        }

        [$findings, $dropped] = $this->sortedByQuotes($round, $this->rounds->findingsOf($round));
        $judgement = new CausesInOneHunk()->checked($this->judgementOf($round, $findings), $findings, $this->rounds->hunksOf($round));
        $judged = JudgedFindings::of($judgement, $findings, $dropped);
        $this->rounds->record($round, $judged);
        $this->events->dispatch(new RoundJudged($command->roundId, $round->checkoutRoot()));
    }

    // Before the judge and the act list, so a finding quoting code the change does not hold is neither judged nor merged with a real one at its place; its reason goes with the round.
    /**
     * @param list<Finding> $findings
     *
     * @return array{list<Finding>, list<DroppedFinding>}
     */
    private function sortedByQuotes(ReviewRound $round, array $findings): array
    {
        if ([] === $findings) {
            return [[], []];
        }

        $change = $this->rounds->changeOf($round);
        $quotes = new QuotesInChange();
        $kept = [];
        $dropped = [];

        foreach ($findings as $finding) {
            $reason = $quotes->reasonToDrop($finding, $change);

            if (null === $reason) {
                $kept[] = $finding;

                continue;
            }

            $dropped[] = DroppedFinding::of($finding, $reason);
        }

        return [$kept, $dropped];
    }

    /**
     * @param list<Finding> $findings
     */
    private function judgementOf(ReviewRound $round, array $findings): Judgement
    {
        // Nothing to mark, so the model is not paid for an empty answer.
        if ([] === $findings) {
            return new Judgement([]);
        }

        try {
            return $this->vendor->judge($round->project(), new JudgeRequest($round->task(), $findings, $round->id(), $this->rounds->filesOf($round)));
        } catch (ProviderRateLimited $limited) {
            throw JudgeLimited::after($limited->retryAfterSeconds);
        } catch (VendorAccountBusy|ProviderFailedTransiently) {
            throw JudgeLimited::after(null);
        } catch (VendorAccountOut) {
            // No wait, because another account of the vendor is free now.
            throw JudgeLimited::after(0);
        }
    }
}
