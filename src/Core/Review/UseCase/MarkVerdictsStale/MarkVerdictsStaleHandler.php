<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\MarkVerdictsStale;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\MarkVerdictsStale;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\PublishVerdict\PublishVerdict;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

// Written under the round's row lock, because a reviewer's worker stores its verdict on the same round at the same time.
#[AsCommandHandler]
final readonly class MarkVerdictsStaleHandler
{
    public function __construct(
        private ReviewRounds $rounds,
        private MessageBusInterface $commands,
    ) {
    }

    public function __invoke(MarkVerdictsStale $command): void
    {
        $round = $this->rounds->stored($command->roundId);

        foreach ($this->rounds->fromRoundOnItsHead($round) as $marked) {
            $this->mark($marked);
        }
    }

    private function mark(Uuid $round): void
    {
        $this->rounds->withLockedRound($round, static function (ReviewRound $locked): void {
            $locked->markVerdictsStale();
        });

        // Published again after every mark, also one already made, because the round's approval may already stand on the forge, and the watcher keeps a head due until both succeed, so its next tick marks the round again.
        $this->commands->dispatch(new PublishVerdict($round->toRfc4122()));
    }
}
