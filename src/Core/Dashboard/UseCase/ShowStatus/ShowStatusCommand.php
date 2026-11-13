<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowStatus;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

// Raw output, because a cause or a change name holding console markup must reach the owner's dashboard as it was recorded.
#[AsCommand(name: 'intakt:status', description: 'Print the status of every project, read from the database alone, as one JSON line')]
final readonly class ShowStatusCommand
{
    public function __construct(
        private StatusReadModel $status,
    ) {
    }

    public function __invoke(OutputInterface $output, #[Option('The output format')] StatusFormat $format = StatusFormat::Json): int
    {
        $output->writeln($this->documentIn($format), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }

    private function documentIn(StatusFormat $format): string
    {
        return match ($format) {
            StatusFormat::Json => $this->status->json(),
        };
    }
}
