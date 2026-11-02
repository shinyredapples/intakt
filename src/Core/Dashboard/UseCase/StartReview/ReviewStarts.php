<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\StartReview;

use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\RoundSource;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\StartReview;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final readonly class ReviewStarts
{
    private const string FLASH_SUCCESS = 'success';
    private const string FLASH_ERROR = 'error';

    public function __construct(
        private LedgerReviewPages $reviews,
        private MessageBusInterface $commands,
    ) {
    }

    /**
     * @return array{string, string}|null
     */
    // Null when the ledger holds no source to start from, so the caller answers that nothing could be started.
    public function startNextOf(string $round, int $number): ?array
    {
        $review = $this->reviews->reviewOf($round);
        $source = $review?->source();

        if (!$review instanceof Review || !$source instanceof RoundSource) {
            return null;
        }

        return $this->started($this->commandFor($review, $source, $number));
    }

    // Built from the ledger and not from the form, because the repository path of a request would be an input to read the disk with; the number is only checked against the one the round gets.
    private function commandFor(Review $review, RoundSource $source, int $number): StartReview
    {
        $round = new RequestedRound($review->change, $number, new ChangeSource($source->repository, $source->base));

        return new StartReview($review->project, $source->task, $round);
    }

    /**
     * @return array{string, string}
     */
    private function started(StartReview $command): array
    {
        try {
            $handled = $this->commands->dispatch($command)->last(HandledStamp::class);
        } catch (HandlerFailedException $failed) {
            $reportable = array_first($failed->getWrappedExceptions(ReportableFailure::class, true));

            return [self::FLASH_ERROR, $reportable?->getMessage() ?? 'The review could not be started.'];
        }

        return [self::FLASH_SUCCESS, 'Started round '.(is_string($handled?->getResult()) ? $handled->getResult() : '').'.'];
    }
}
