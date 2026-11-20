<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\MakeBuildClone;

use Intakt\Core\Git\Domain\SourceLine;
use Intakt\Core\Git\Domain\TaskBranch;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'intakt:build:clone', description: 'Clone main and the task\'s branch, and no other ref, from the project\'s bare mirror into a new folder')]
final readonly class MakeBuildCloneCommand
{
    public const string SOURCE_IGNORED = 'The Source line of this task is ignored, because its author has no write access to the project; the build starts from main and the task\'s branch only.';

    public function __construct(
        private MakeBuildClone $clones,
        private Filesystem $filesystem,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] MakeBuildCloneInput $input): int
    {
        try {
            $branch = $this->taskBranch($io, $input);
            $this->clones->make($input->project, $branch, $input->directory);
        } catch (IOException|ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->writeln('clone '.OutputFormatter::escape($input->directory));

        return Command::SUCCESS;
    }

    private function taskBranch(SymfonyStyle $io, MakeBuildCloneInput $input): TaskBranch
    {
        if (null === $input->task) {
            return new TaskBranch($input->branch);
        }

        $text = $this->filesystem->readFile($input->task);

        if ($input->trustedAuthor) {
            return new TaskBranch($input->branch, SourceLine::in($text));
        }

        // One comment for the task however many lines it has, because a source line of an author without write access must not steer the build.
        if (SourceLine::isNamedIn($text)) {
            $io->writeln('comment '.self::SOURCE_IGNORED);
        }

        return new TaskBranch($input->branch);
    }
}
