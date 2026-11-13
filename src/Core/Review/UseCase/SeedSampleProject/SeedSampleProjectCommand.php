<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\SeedSampleProject;

use Intakt\Core\Ai\Contract\Provider;
use SensitiveParameter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(name: 'intakt:sample:seed', description: 'Build the sample repository and write the sample project with its review panel, each only when it does not exist yet')]
final readonly class SeedSampleProjectCommand
{
    public function __construct(
        private SampleRepository $repository,
        private SampleProject $project,
        private LockFactory $locks,
        #[Autowire(env: 'REVIEW_MCP_BASE_URL')]
        private string $baseUrl,
        #[SensitiveParameter]
        #[Autowire(env: 'MCP_OWNER_TOKEN')]
        private string $ownerToken,
        #[Autowire(env: 'INTAKT_COMPOSE_SERVICE')]
        private string $composeService,
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

        if (!$this->seedProject($io, $configuration)) {
            return Command::FAILURE;
        }

        $this->seedRepository($io, $repository);
        $this->printReviewCommand($io, $repository);
        $this->printMcpSetup($io);

        return Command::SUCCESS;
    }

    private function printReviewCommand(SymfonyStyle $io, string $repository): void
    {
        $io->writeln(OutputFormatter::escape(sprintf(
            'Review the sample change: %sphp bin/console intakt:review %s --project=%s --repository=%s --base=%s --task="%s"',
            $this->hostPrefix(),
            SampleRepository::BRANCH,
            SampleProject::NAME,
            $repository,
            SampleRepository::BASE,
            SampleProject::TASK,
        )));
    }

    // Prefixed in the compose demo, because its reader copies the line into the host shell and PHP runs only inside the service's container.
    private function hostPrefix(): string
    {
        return '' === $this->composeService ? '' : sprintf('docker compose exec %s ', $this->composeService);
    }

    private function printMcpSetup(SymfonyStyle $io): void
    {
        if ('' === $this->ownerToken) {
            return;
        }

        $io->writeln(OutputFormatter::escape(sprintf(
            'Connect an MCP client: claude mcp add --transport http intakt %s/mcp/owner --header "Authorization: Bearer %s"',
            rtrim($this->baseUrl, '/'),
            $this->ownerToken,
        )));
    }

    private function seedProject(SymfonyStyle $io, string $configuration): bool
    {
        if ($this->project->isWritten($configuration)) {
            $io->writeln(OutputFormatter::escape(sprintf('Kept the project configuration %s; remove it to seed the sample project again.', $configuration)));

            return true;
        }

        $providers = $this->project->keyedProviders();

        if ([] === $providers) {
            $io->error(sprintf('Set %s to seed the sample project.', implode(' or ', $this->project->keyVariables())));

            return false;
        }

        $this->project->write($configuration, $providers);
        $io->writeln(OutputFormatter::escape(sprintf(
            'Wrote the sample project "%s" to %s, its panel on %s.',
            SampleProject::NAME,
            $configuration,
            implode(' and ', array_map(static fn (Provider $provider): string => $provider->value, $providers)),
        )));

        return true;
    }

    private function seedRepository(SymfonyStyle $io, string $repository): void
    {
        $message = $this->repository->buildOnce($repository)
            ? 'Built the sample repository in %s: branch %s against %s.'
            : 'Kept the sample repository in %s (branch %s against %s).';

        $io->writeln(OutputFormatter::escape(sprintf($message, $repository, SampleRepository::BRANCH, SampleRepository::BASE)));
    }
}
