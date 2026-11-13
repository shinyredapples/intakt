<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\UseCase\ListCiChanges;

use Intakt\Plugin\GitHubForge\Domain\CiDefinition;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFile;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

// A command is the door because no push reads the changed paths yet; it shows the verdict that push will get.
#[AsCommand(name: 'intakt:forge:ci-paths', description: 'List the changed paths that are part of the CI definition the given workflow files make up')]
final readonly class ListCiChangesCommand
{
    public function __construct(
        private CiDefinition $ciDefinition,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @param list<string> $paths
     * @param list<string> $workflow
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The changed paths, relative to the repository root')]
        array $paths,
        #[Option('A workflow file whose text names the files it runs')]
        array $workflow = [],
    ): int {
        $workflows = array_map(fn (string $file): WorkflowFile => $this->read($io, $file), $workflow);
        $ciPaths = $this->ciDefinition->changedAmong($workflows, $paths);
        foreach ($ciPaths as $path) {
            $io->writeln(sprintf('ci %s', $path));
        }

        $io->writeln(sprintf('CI change: %s', [] === $ciPaths ? 'no' : 'yes'));

        return Command::SUCCESS;
    }

    private function read(SymfonyStyle $io, string $file): WorkflowFile
    {
        try {
            return new WorkflowFile($file, $this->filesystem->readFile($file));
        } catch (IOException $failure) {
            $io->writeln(sprintf('unreadable %s: %s', $file, $failure->getMessage()));

            return WorkflowFile::unread($file, $failure->getMessage());
        }
    }
}
