<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\DecideDependencyChange;

use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Plugin\Language\Contract\DependencyDecision;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:dependencies:decide', description: 'Decide whether a head\'s dependency change is routine or needs the owner, against the files at its merge base with main')]
final readonly class DecideDependencyChangeCommand
{
    public function __construct(
        private DecideDependencyChange $decide,
        private Mirrors $mirrors,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The project name, whose bare mirror holds the head and main')]
        string $project,
        #[Argument(description: 'The full commit name of the head')]
        string $head,
    ): int {
        try {
            $decision = $this->decide->of($this->mirrors->pathOf($project), $head);
        } catch (InvalidArgumentException $refused) {
            $io->error($refused->getMessage());

            return Command::FAILURE;
        }

        $this->show($io, $decision);

        return Command::SUCCESS;
    }

    private function show(SymfonyStyle $io, DependencyDecision $decision): void
    {
        foreach ($decision->newPackages as $package) {
            $io->writeln('new package '.$this->printable($package));
        }

        foreach ($decision->findings as $finding) {
            $io->writeln(sprintf('owner %s: %s', $this->printable($finding->subject), $this->printable($finding->reason)));
        }

        $io->writeln('decision: '.($decision->needsOwner() ? 'needs the owner' : 'routine'));
    }

    private function printable(string $text): string
    {
        // Because the names come from the change's own files, where a newline or an escape sequence would forge a line of this listing or steer the terminal.
        return OutputFormatter::escape(addcslashes($text, "\0..\37\177\\"));
    }
}
