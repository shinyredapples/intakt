<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Mcp\Contract\OwnerClientLine;
use Intakt\Core\Mcp\Contract\OwnerToken;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\SampleChange;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\SignalRegistry\SignalRegistry;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// A second door of intakt:review with the sample's values built in, so a first try needs no long command line; real projects keep intakt:review.
#[AsCommand(name: 'intakt:demo', description: 'Review the sample change and print the outcome, the act list, the review\'s dashboard address and the MCP client line')]
final readonly class DemoReviewCommand implements SignalableCommandInterface
{
    public function __construct(
        private LocalChangeReview $review,
        private ReviewReport $report,
        private UrlGeneratorInterface $urls,
        #[Autowire(env: 'default::INTAKT_SAMPLE_REPOSITORY')]
        private ?string $repository,
        #[Autowire(env: 'int:DASHBOARD_PORT')]
        private int $dashboardPort,
        #[Autowire(env: 'REVIEW_MCP_BASE_URL')]
        private string $mcpBaseUrl,
        private OwnerToken $ownerToken,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if (null === $this->repository || '' === $this->repository) {
            $io->error('INTAKT_SAMPLE_REPOSITORY is not set: set it to the folder intakt:sample:seed built the sample repository in, or review your own project with intakt:review.');

            return Command::FAILURE;
        }

        try {
            $review = $this->review->of($this->inputForSample($this->repository));
        } catch (ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $this->printVerdict($io, $review);
        $this->printDashboardAddress($io, $review);
        $this->printMcpLine($io);

        return ReviewExit::of($review->decision)->value;
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return SignalRegistry::isSupported() ? [ReviewChangeCommand::INTERRUPT, ReviewChangeCommand::TERMINATE] : [];
    }

    // The previous exit code is widened to mixed because the interface requires the parameter and the command ignores it: a stop ends as intakt:review's does.
    public function handleSignal(int $signal, mixed $previousExitCode = 0): int
    {
        $this->review->stop();

        return ReviewChangeCommand::SIGNAL_EXIT_BASE + $signal;
    }

    private function inputForSample(string $repository): ReviewChangeInput
    {
        $input = new ReviewChangeInput();
        $input->change = SampleChange::CHANGE;
        $input->project = SampleChange::PROJECT;
        $input->task = SampleChange::TASK;
        $input->repository = $repository;
        $input->base = SampleChange::BASE;

        return $input;
    }

    private function printVerdict(SymfonyStyle $io, ChangeReview $review): void
    {
        foreach ($this->report->asLines($review) as $line) {
            $io->writeln($line);
        }
    }

    private function printDashboardAddress(SymfonyStyle $io, ChangeReview $review): void
    {
        $io->writeln(sprintf('Open the review: http://localhost:%d%s', $this->dashboardPort, $this->urls->generate(DashboardRoutes::SHOW_REVIEW, ['round' => $review->roundId])));
    }

    // Escaped and on one line, because the line is pasted as one command and the token may hold characters the console reads as tags.
    private function printMcpLine(SymfonyStyle $io): void
    {
        $io->writeln(OutputFormatter::escape(new OwnerClientLine($this->mcpBaseUrl, $this->ownerToken->value())->setup()));
    }
}
