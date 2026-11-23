<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\StartReview;

use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\RoundSource;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\RoundLock;
use Intakt\Core\Dashboard\Infrastructure\SampleChangeSource;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\SampleChange;
use Intakt\Core\Review\Contract\StartReview;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final readonly class ReviewStarts
{
    private const string FLASH_TOAST = 'toast';
    private const string FLASH_ERROR = 'error';

    public function __construct(
        private LedgerReviewPages $reviews,
        private MessageBusInterface $commands,
        private SampleChangeSource $sample,
        private RoundLock $locks,
    ) {
    }

    // Null when this install has no sample change, so the caller answers that nothing could be started; the number is always 1, so a second press is refused as not next.
    public function startSampleChange(): ?StartAnswer
    {
        $source = $this->sample->source();

        if (!$source instanceof ChangeSource) {
            return null;
        }

        return $this->started(new StartReview(SampleChange::PROJECT, SampleChange::TASK, new RequestedRound(SampleChange::CHANGE, 1, $source)));
    }

    // Null when the ledger holds no source to start from, so the caller answers that nothing could be started.
    public function startNextOf(string $round, int $number): ?StartAnswer
    {
        $review = $this->reviews->reviewOf($round);
        $source = $review?->source();

        if (!$review instanceof Review || !$source instanceof RoundSource) {
            return null;
        }

        // Taken only now, for a round the ledger knows, and released by RoundLock once the answer is built; keyed by the review's first round, so two presses that arrive together from two round pages of one review are kept apart too, and RoundNotNext still refuses a second press that comes later.
        if (!$this->locks->tryHolding($review->firstRound())) {
            return new StartAnswer([self::FLASH_ERROR, 'Another press for this review is still running, so nothing was started.']);
        }

        return $this->started($this->commandFor($review, $source, $number));
    }

    // Built from the ledger and not from the form, because the repository path of a request would be an input to read the disk with; the number is only checked against the one the round gets.
    private function commandFor(Review $review, RoundSource $source, int $number): StartReview
    {
        $round = new RequestedRound($review->change, $number, new ChangeSource($source->repository, $source->base));

        return new StartReview($review->project, $source->task, $round);
    }

    private function started(StartReview $command): StartAnswer
    {
        try {
            $handled = $this->commands->dispatch($command)->last(HandledStamp::class);
        } catch (PanelNotConfigured) {
            // The panel's own message names a configuration term a newcomer does not know yet; the CLI and MCP keep it.
            return new StartAnswer([self::FLASH_ERROR, 'This project has no reviewers set up yet, so nothing was started.']);
        } catch (ReportableFailure $refusal) {
            return new StartAnswer([self::FLASH_ERROR, $refusal->getMessage()]);
        }

        $round = $handled?->getResult();
        $round = is_string($round) && '' !== $round ? $round : null;

        // Named by the number the round was asked to get and not by its id, because a person reads the number and the id only pushed the page around (#2370, D4).
        return new StartAnswer([self::FLASH_TOAST, sprintf('Started round %d.', $command->round->number)], $round);
    }
}
