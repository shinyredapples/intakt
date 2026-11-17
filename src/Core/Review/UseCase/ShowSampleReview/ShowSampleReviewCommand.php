<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ShowSampleReview;

use Intakt\Core\Mcp\Contract\OwnerClientLine;
use Intakt\Core\Mcp\Contract\OwnerToken;
use Intakt\Core\Review\Contract\ReviewServices;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleProject;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// A reader runs this after the demo's start to get the command that reviews the sample change, so it only offers that command once the sample project is written.
#[AsCommand(name: 'intakt:sample:show', description: 'Print the dashboard address, the command that reviews the sample change and the MCP setup, framed')]
final readonly class ShowSampleReviewCommand
{
    // Ruled lines instead of a styled block, because a block wraps long lines and the copied command would break at the wrap.
    private const string RULE = '========================================================================';

    public function __construct(
        #[Autowire(env: 'REVIEW_MCP_BASE_URL')]
        private string $baseUrl,
        private OwnerToken $ownerToken,
        #[Autowire(env: 'INTAKT_COMPOSE_SERVICE')]
        private string $composeService,
        #[Autowire(env: 'int:DASHBOARD_PORT')]
        private int $dashboardPort,
        private SampleProject $project,
        #[Autowire(param: ReviewServices::PROJECT_FILE)]
        private string $configuration,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The directory the sample repository was built in')] string $repository,
    ): int {
        $io->newLine();
        $io->writeln(self::RULE);
        $this->printDashboardAddress($io);
        $this->printReviewCommand($io, $repository);
        $this->printMcpSetup($io);
        $io->writeln(self::RULE);

        return Command::SUCCESS;
    }

    private function printDashboardAddress(SymfonyStyle $io): void
    {
        $io->writeln(sprintf('Open the dashboard: http://localhost:%d', $this->dashboardPort));
    }

    private function printReviewCommand(SymfonyStyle $io, string $repository): void
    {
        // A start without a key leaves the project file unwritten, so the review command would name a project that does not exist.
        if (!$this->project->isWritten($this->configuration)) {
            $io->writeln(sprintf('No sample project yet: set %s and start again to write it, then run intakt:sample:show again.', implode(' or ', $this->project->keyVariables())));

            return;
        }

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
        $io->writeln(OutputFormatter::escape(new OwnerClientLine($this->baseUrl, $this->ownerToken->value())->setup()));
    }
}
