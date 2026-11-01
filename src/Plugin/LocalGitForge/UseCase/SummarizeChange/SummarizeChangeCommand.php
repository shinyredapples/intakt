<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\SummarizeChange;

use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Plugin\LocalGitForge\Infrastructure\VisiblePath;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:change:summarize', description: 'Print the change summary of a branch of a local repository against its base branch')]
final readonly class SummarizeChangeCommand
{
    public function __construct(
        private NameStatusParser $nameStatus,
        private VisiblePath $paths = new VisiblePath(),
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] SummarizeChangeInput $input): int
    {
        try {
            $change = new LocalGitForge(new LocalRepository($input->repository, $input->base), $this->nameStatus)->change(new ChangeId($input->branch));
        } catch (ChangeNotFound|ForgeReadFailed|InvalidArgumentException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $this->print($io, $change, $input->base);

        return Command::SUCCESS;
    }

    private function print(SymfonyStyle $io, Change $change, string $base): void
    {
        // Names may contain "<info>", which the formatter would swallow; branch names skip VisiblePath because git refuses control characters in ref names.
        $io->writeln(OutputFormatter::escape(sprintf('%s against %s', $change->id->value, $base)));
        $io->writeln(sprintf('base %s, head %s', substr($change->commits->base->hash, 0, 12), substr($change->commits->head->hash, 0, 12)));
        $io->writeln(sprintf('%s, +%d -%d lines', $this->countFiles($change), $change->diff->addedLines(), $change->diff->removedLines()));

        foreach ($change->diff->files as $file) {
            $io->writeln($this->describe($file));
        }
    }

    private function countFiles(Change $change): string
    {
        $count = count($change->diff->files);

        return 1 === $count ? '1 file changed' : sprintf('%d files changed', $count);
    }

    private function describe(ChangedFile $file): string
    {
        return sprintf('%-9s %s', $file->status->value, $this->paths->of($file->path));
    }
}
