<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\RemoveDemoProject;

use Intakt\Core\Dashboard\Infrastructure\LiveBoardHeadline;
use Intakt\Core\Dashboard\Infrastructure\LiveTrends;
use Intakt\Core\Dashboard\Infrastructure\ReplayedReviewRows;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\UseCase\CreateDemoProject\CreateDemoProjectCommand;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplaysRecording;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RemoveReplayedEvents;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\UnflagReplayProject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

// Only on the compose demo and only for a project flagged as a replay, because the ledger keeps every live row and this is the one command that deletes rows from it.
#[AsCommand(name: 'intakt:demo:remove', description: 'Remove the demo project with the ledger rows replayed into it; only on the compose demo')]
final readonly class RemoveDemoProjectCommand
{
    public function __construct(
        private MessageBusInterface $commands,
        private ReplayMarks $marks,
        private ReplayedReviewRows $rows,
        private LockFactory $locks,
        private LiveBoardHeadline $headline,
        private LiveTrends $trends,
        #[Autowire(env: 'INTAKT_COMPOSE_SERVICE')]
        private string $composeService,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if ('' === $this->composeService) {
            $io->error('The demo project is removed only on the compose demo, and INTAKT_COMPOSE_SERVICE is empty on this install.');

            return Command::FAILURE;
        }

        // The replay's lock, because rows a running replay appends after the read below would stay behind and read as live once the flag is gone.
        $lock = $this->locks->createLock(ReplaysRecording::LOCK);

        if (!$lock->acquire()) {
            $io->error('A replay is running; remove the demo project when it has ended.');

            return Command::FAILURE;
        }

        try {
            $io->success($this->removeTheDemoProject());
        } finally {
            $lock->release();
        }

        // Pushed to open pages, because no round follows a removal and a board left empty would otherwise keep its octopus hidden until a reload, and Trends would keep counting the removed review.
        $this->headline->publishTheBoard();
        $this->trends->publishTheTrends();

        return Command::SUCCESS;
    }

    // The flag goes last, so a removal that stops partway still finds a replay the next time and finishes it.
    private function removeTheDemoProject(): string
    {
        if (ReplayMark::Replay !== $this->marks->ofProject(CreateDemoProjectCommand::NAME)) {
            return sprintf('The project %s is not marked as a replay, so nothing is removed.', CreateDemoProjectCommand::NAME);
        }

        $rows = $this->rows->ofProject(CreateDemoProjectCommand::NAME);
        $this->commands->dispatch(new RemoveReplayedEvents(array_map(static fn (RecordedEvent $row): Uuid => $row->id, $rows)));
        $this->commands->dispatch(new UnflagReplayProject(CreateDemoProjectCommand::NAME));

        return sprintf('Removed the demo project %s with its %d ledger rows.', CreateDemoProjectCommand::NAME, count($rows));
    }
}
