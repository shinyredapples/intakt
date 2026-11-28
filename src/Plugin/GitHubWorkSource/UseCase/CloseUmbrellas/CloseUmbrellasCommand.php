<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\UseCase\CloseUmbrellas;

use Intakt\Plugin\GitHubWorkSource\Domain\ClosedUmbrellas;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\GitHubWorkSource;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:umbrellas:close', description: 'Take a complete listing of a project and close every umbrella of a finished split whose parts are all closed, as the pick does on each tick')]
final readonly class CloseUmbrellasCommand
{
    public function __construct(
        private GitHubWorkSource $workSource,
    ) {
    }

    // The project name is left out of every line, because it is the operator's own text and the lines need no markup filter then.
    public function __invoke(SymfonyStyle $io, #[Argument('The project, as named in the configuration')] string $project): int
    {
        $result = $this->workSource->closeFinishedUmbrellas($project);
        if (!$result instanceof ClosedUmbrellas) {
            $io->getErrorStyle()->error('The listing came back short, so no umbrella is closed; the log says why.');

            return Command::FAILURE;
        }

        $this->printClosed($io, $result);
        foreach ($result->stayedOpen as $number => $reason) {
            $io->getErrorStyle()->error(sprintf('Umbrella #%d has every part closed but stays open: %s', $number, $reason));
        }

        return [] === $result->stayedOpen ? Command::SUCCESS : Command::FAILURE;
    }

    private function printClosed(SymfonyStyle $io, ClosedUmbrellas $result): void
    {
        if ([] === $result->closed && [] === $result->stayedOpen) {
            $io->writeln('No umbrella has every part closed.');

            return;
        }

        foreach ($result->closed as $split) {
            $io->writeln(sprintf('Closed umbrella #%d: all parts are closed (%s).', $split->umbrella, implode(', ', array_map(static fn (int $part): string => '#'.$part, $split->parts))));
        }
    }
}
