<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\RoundJudged;
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

        $findings = $this->rounds->findingsOf($round);
        $judged = JudgedFindings::of($this->judgementOf($round, $findings), $findings);
        $this->rounds->record($round, $judged);
        $this->events->dispatch(new RoundJudged($command->roundId, $round->checkoutRoot()));
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
            return $this->vendor->judge($round->project(), new JudgeRequest($round->task(), $findings, $round->id()));
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
