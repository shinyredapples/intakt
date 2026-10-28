<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\SummarizeChange;

use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:change:summarize', description: 'Print the change summary of a branch of a local repository against its base branch')]
final readonly class SummarizeChangeCommand
{
    public function __construct(private NameStatusParser $nameStatus)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The branch to summarize')]
        string $branch,
        #[Option('The local repository, relative to the working directory or absolute')]
        string $repository = '.',
        #[Option('The branch the change is measured against')]
        string $base = 'main',
    ): int {
        try {
            $change = new LocalGitForge(new LocalRepository($repository, $base), $this->nameStatus)->change(new ChangeId($branch));
        } catch (ChangeNotFound|ForgeReadFailed|InvalidArgumentException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $this->print($io, $change, $base);

        return Command::SUCCESS;
    }

    private function print(SymfonyStyle $io, Change $change, string $base): void
    {
        $io->writeln(sprintf('%s against %s', $change->id->value, $base));
        $io->writeln(sprintf('base %s, head %s', substr($change->commits->base->hash, 0, 12), substr($change->commits->head->hash, 0, 12)));
        $io->writeln(sprintf('%d files changed, +%d -%d lines', count($change->diff->files), $this->countLines($change, '+'), $this->countLines($change, '-')));

        foreach ($change->diff->files as $file) {
            $io->writeln($this->describe($file));
        }
    }

    private function describe(ChangedFile $file): string
    {
        return sprintf('%-9s %s', $file->status->value, $file->path);
    }

    private function countLines(Change $change, string $marker): int
    {
        // The "+++" and "---" file headers also start with the marker and are not changed lines.
        $lines = explode("\n", $change->diff->patch);

        return count(array_filter(
            $lines,
            static fn (string $line): bool => str_starts_with($line, $marker) && !str_starts_with($line, str_repeat($marker, 3)),
        ));
    }
}
