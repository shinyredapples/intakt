<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\ReplayMarkOfProject;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

// The project's mark rather than each round's, because the dashboard reads its rounds from the ledger, and a round the ledger names need not be stored as a round the Review module can answer for.
final readonly class ReplayMarks
{
    public function __construct(
        #[Autowire(service: 'query.bus')]
        private MessageBusInterface $queries,
    ) {
    }

    public function ofProject(string $project): ReplayMark
    {
        $mark = $this->queries->dispatch(new ReplayMarkOfProject($project))->last(HandledStamp::class)?->getResult();

        return $mark instanceof ReplayMark ? $mark : throw new LogicException('The replay mark query did not answer a mark.');
    }
}
