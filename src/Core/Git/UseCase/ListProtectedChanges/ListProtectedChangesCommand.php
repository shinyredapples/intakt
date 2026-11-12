<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\ListProtectedChanges;

use Intakt\Core\Git\Contract\GitServices;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProtectedChanges;
use Intakt\Core\Git\Domain\ProtectedPaths;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'intakt:protected:list', description: 'List the protected paths a head touches, read from the whole change since the merge base with main and from each of its commits')]
final readonly class ListProtectedChangesCommand
{
    /**
     * @param array<string, list<string>> $protectedPaths
     */
    public function __construct(
        private ListProtectedChanges $changes,
        private Mirrors $mirrors,
        #[Autowire(param: GitServices::PROTECTED_PATHS)]
        private array $protectedPaths,
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
            $changes = $this->changes->of($this->mirrors->pathOf($project), $head, new ProtectedPaths($this->protectedPaths[$project] ?? []));
        } catch (InvalidArgumentException $refused) {
            $io->error($refused->getMessage());

            return Command::FAILURE;
        }

        $this->show($io, $changes);

        return Command::SUCCESS;
    }

    private function show(SymfonyStyle $io, ProtectedChanges $changes): void
    {
        foreach ($changes->paths as $path) {
            $io->writeln('protected '.$this->printable($path));
        }

        if (null !== $changes->unreadable) {
            $io->writeln('unreadable '.$this->printable($changes->unreadable));
        }

        $io->writeln('touches protected paths: '.($changes->touchesProtectedPaths() ? 'yes' : 'no'));
    }

    private function printable(string $text): string
    {
        // Because git allows a newline or an escape sequence in a file name, which would forge a line of this listing or steer the terminal.
        return OutputFormatter::escape(addcslashes($text, "\0..\37\177\\"));
    }
}
