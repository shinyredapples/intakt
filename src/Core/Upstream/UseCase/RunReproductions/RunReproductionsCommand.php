<?php

declare(strict_types=1);

namespace Intakt\Core\Upstream\UseCase\RunReproductions;

use Composer\InstalledVersions;
use FilesystemIterator;
use Intakt\Core\Upstream\Contract\ReproductionOutcome;
use Intakt\Core\Upstream\Infrastructure\ReproductionRunner;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;
use UnexpectedValueException;

#[AsCommand(name: 'intakt:upstream:run', description: 'Run every upstream reproduction and report which still fail')]
final readonly class RunReproductionsCommand
{
    public function __construct(
        private ReproductionRunner $runner,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Folder with one subfolder per reproduction, relative to the project or absolute')]
        string $directory = 'upstream',
    ): int {
        $folder = Path::makeAbsolute($directory, $this->projectDir);

        // A mistyped or unreadable folder would otherwise read as a clean run where every bug is fixed.
        if (!is_dir($folder) || !is_readable($folder)) {
            $io->error(sprintf('No readable reproduction folder at %s.', $directory));

            return Command::INVALID;
        }

        $this->reportVersions($io);
        $folders = $this->discover($folder);

        if (null === $folders) {
            $io->error(sprintf('Could not list the reproductions in %s.', $directory));

            return Command::INVALID;
        }

        $outcomes = array_map($this->runner->run(...), $folders);
        $this->report($io, $outcomes);

        return array_any($outcomes, static fn (ReproductionOutcome $outcome): bool => $outcome->stillFails()) ? Command::FAILURE : Command::SUCCESS;
    }

    private function reportVersions(SymfonyStyle $io): void
    {
        foreach (InstalledVersions::getInstalledPackages() as $package) {
            if (str_starts_with($package, 'symfony/ai-') || str_starts_with($package, 'mcp/')) {
                $io->writeln(sprintf('%s %s', $package, InstalledVersions::getPrettyVersion($package)));
            }
        }
    }

    /**
     * @return list<string>|null
     */
    private function discover(string $folder): ?array
    {
        // Without search permission no child reads as a folder, so the run would pass as clean.
        if (!is_executable($folder)) {
            return null;
        }

        // A glob pattern would read brackets or stars in the folder's own path as wildcards, and a listing error must not pass as an empty folder.
        try {
            $paths = array_keys(iterator_to_array(new FilesystemIterator($folder, FilesystemIterator::KEY_AS_PATHNAME | FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS)));
        } catch (UnexpectedValueException) {
            return null;
        }

        $folders = array_filter(
            $paths,
            static fn (string $path): bool => is_dir($path) && !str_starts_with(basename($path), '.'),
        );
        sort($folders);

        return $folders;
    }

    /**
     * @param list<ReproductionOutcome> $outcomes
     */
    private function report(SymfonyStyle $io, array $outcomes): void
    {
        foreach ($outcomes as $outcome) {
            $io->writeln(sprintf('%-12s %s', $outcome->status->value, $outcome->slug));

            if ($outcome->stillFails() && $io->isVerbose()) {
                // The output comes from the reproduction, so a tag in it must not restyle or hide the report.
                $io->writeln($outcome->output, OutputInterface::OUTPUT_RAW);
            }
        }

        $failing = count(array_filter($outcomes, static fn (ReproductionOutcome $outcome): bool => $outcome->stillFails()));
        $io->writeln(sprintf('%d reproductions, %d still fail', count($outcomes), $failing));
    }
}
