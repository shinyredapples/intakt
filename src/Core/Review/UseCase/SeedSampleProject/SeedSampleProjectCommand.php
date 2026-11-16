<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\SeedSampleProject;

use Intakt\Core\Ai\Contract\Provider;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(name: 'intakt:sample:seed', description: 'Build the sample repository and write the sample project with its review panel, each only when it does not exist yet')]
final readonly class SeedSampleProjectCommand
{
    public function __construct(
        private SampleRepository $repository,
        private SampleProject $project,
        private LockFactory $locks,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The directory the sample repository is built in')] string $repository,
        #[Argument('The project configuration file to write, the one INTAKT_CONFIG names')] string $configuration,
    ): int {
        // Held while each part is checked and written, so two starts on one volume cannot both build it.
        $lock = $this->locks->createLock('review-sample-seed');
        $lock->acquire(true);

        $this->seedProject($io, $configuration);
        $this->seedRepository($io, $repository);

        return Command::SUCCESS;
    }

    private function seedProject(SymfonyStyle $io, string $configuration): void
    {
        if ($this->project->isWritten($configuration)) {
            $io->writeln(OutputFormatter::escape(sprintf('Kept the project configuration %s; remove it to seed the sample project again.', $configuration)));

            return;
        }

        $providers = $this->project->keyedProviders();

        // A success, so a start without a key still opens the dashboard; the file stays unwritten, so the next start with a key writes it.
        if ([] === $providers) {
            $io->warning(sprintf('Set %s to seed the sample project; the next start with one writes it.', implode(' or ', $this->project->keyVariables())));

            return;
        }

        $this->project->write($configuration, $providers);
        $io->writeln(OutputFormatter::escape(sprintf(
            'Wrote the sample project "%s" to %s, its panel on %s.',
            SampleProject::NAME,
            $configuration,
            implode(' and ', array_map(static fn (Provider $provider): string => $provider->value, $providers)),
        )));
    }

    private function seedRepository(SymfonyStyle $io, string $repository): void
    {
        $message = $this->repository->buildOnce($repository)
            ? 'Built the sample repository in %s: branch %s against %s.'
            : 'Kept the sample repository in %s (branch %s against %s).';

        $io->writeln(OutputFormatter::escape(sprintf($message, $repository, SampleRepository::BRANCH, SampleRepository::BASE)));
    }
}
