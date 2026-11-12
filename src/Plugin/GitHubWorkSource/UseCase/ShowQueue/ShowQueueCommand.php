<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\UseCase\ShowQueue;

use Intakt\Plugin\WorkSource\Contract\WorkSource;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:queue:show', description: 'Show the order in which a project\'s open issues would be built')]
final readonly class ShowQueueCommand
{
    public function __construct(
        private WorkSource $source,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The project, as named in the configuration')]
        string $project,
    ): int {
        $queue = $this->source->queue($project);
        if (!$queue->complete) {
            $io->error('The open issues were not listed completely, so no order is shown; see the log for the reason.');

            return Command::FAILURE;
        }

        foreach ($queue->ready() as $position => $task) {
            $io->writeln(sprintf('%d. #%d %s', $position + 1, $task->number, $task->title));
        }

        foreach ($queue->held() as $held) {
            $io->writeln(sprintf('held #%d %s (%s)', $held->task->number, $held->task->title, $held->reason));
        }

        return Command::SUCCESS;
    }
}
