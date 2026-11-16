<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ShowSampleReview;

use Intakt\Core\Mcp\Contract\OwnerClientLine;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleProject;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleRepository;
use SensitiveParameter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// The demo's start runs this once the server answers, so the command a reader copies works at once and is the last thing the start prints.
#[AsCommand(name: 'intakt:sample:show', description: 'Print the dashboard address, the command that reviews the sample change and the MCP setup, framed')]
final readonly class ShowSampleReviewCommand
{
    // Ruled lines instead of a styled block, because a block wraps long lines and the copied command would break at the wrap.
    private const string RULE = '========================================================================';

    public function __construct(
        #[Autowire(env: 'REVIEW_MCP_BASE_URL')]
        private string $baseUrl,
        #[SensitiveParameter]
        #[Autowire(env: 'MCP_OWNER_TOKEN')]
        private string $ownerToken,
        #[Autowire(env: 'INTAKT_COMPOSE_SERVICE')]
        private string $composeService,
        #[Autowire(env: 'int:DASHBOARD_PORT')]
        private int $dashboardPort,
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
        $client = new OwnerClientLine($this->baseUrl, $this->ownerToken);
        if (!$client->hasToken()) {
            return;
        }

        $io->writeln(OutputFormatter::escape('Connect an MCP client: '.$client->line()));
    }
}
