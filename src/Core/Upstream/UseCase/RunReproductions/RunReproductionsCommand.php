<?php

declare(strict_types=1);

namespace Intakt\Core\Upstream\UseCase\RunReproductions;

use Composer\InstalledVersions;
use Intakt\Core\Upstream\Contract\ReproductionOutcome;
use Intakt\Core\Upstream\Infrastructure\PhpunitReproductionRunner;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;

#[AsCommand(name: 'intakt:upstream:run', description: 'Run every upstream reproduction and report which still fail')]
final readonly class RunReproductionsCommand
{
    public function __construct(
        private PhpunitReproductionRunner $runner,
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

        // A mistyped folder would otherwise read as a clean run where every bug is fixed.
        if (!is_dir($folder)) {
            $io->error(sprintf('No reproduction folder at %s.', $directory));

            return Command::INVALID;
        }

        $this->reportVersions($io);
        $outcomes = $this->runAll($folder);
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
     * @return list<ReproductionOutcome>
     */
    private function runAll(string $folder): array
    {
        $folders = glob($folder.'/*', GLOB_ONLYDIR);

        return array_map($this->runner->run(...), false === $folders ? [] : $folders);
    }

    /**
     * @param list<ReproductionOutcome> $outcomes
     */
    private function report(SymfonyStyle $io, array $outcomes): void
    {
        foreach ($outcomes as $outcome) {
            $io->writeln(sprintf('%-12s %s', $outcome->status->value, $outcome->slug));

            if ($outcome->stillFails() && $io->isVerbose()) {
                $io->writeln($outcome->output);
            }
        }

        $failing = count(array_filter($outcomes, static fn (ReproductionOutcome $outcome): bool => $outcome->stillFails()));
        $io->writeln(sprintf('%d reproductions, %d still fail', count($outcomes), $failing));
    }
}
