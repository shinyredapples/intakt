<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\ReplayMarkOfProject;
use Intakt\Core\Review\Contract\ReplayMarkOfRound;
use Intakt\Core\Review\Contract\UnknownRound;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final readonly class ReplayMarks
{
    public function __construct(
        #[Autowire(service: 'query.bus')]
        private MessageBusInterface $queries,
    ) {
    }

    public function ofProject(string $project): ReplayMark
    {
        return $this->answerTo(new ReplayMarkOfProject($project));
    }

    // The round's stored mark first, because a round keeps the mark it opened with and a project flagged later must not relabel it; the project's only for a round the ledger names but the Review module never stored.
    public function ofRound(string $round, string $project): ReplayMark
    {
        try {
            return $this->answerTo(new ReplayMarkOfRound($round));
        } catch (HandlerFailedException $failed) {
            return [] === $failed->getWrappedExceptions(UnknownRound::class, true) ? throw $failed : $this->ofProject($project);
        }
    }

    private function answerTo(ReplayMarkOfProject|ReplayMarkOfRound $query): ReplayMark
    {
        $mark = $this->queries->dispatch($query)->last(HandledStamp::class)?->getResult();

        return $mark instanceof ReplayMark ? $mark : throw new LogicException('The replay mark query did not answer a mark.');
    }
}
