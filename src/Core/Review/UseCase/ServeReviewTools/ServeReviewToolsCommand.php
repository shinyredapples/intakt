<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ServeReviewTools;

use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\ReadVerdicts\RoundNotFound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:review:serve-tools', description: 'Print the address and the bearer credential through which an MCP client reads the head of one review round')]
final readonly class ServeReviewToolsCommand
{
    public const string PATH = '/mcp/review';

    public function __construct(private ReviewRounds $rounds, private ReviewCredential $credential)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Argument(description: 'UUID of the review round')] string $round): int
    {
        try {
            $state = $this->rounds->stored($round)->state();
        } catch (RoundNotFound $notFound) {
            $io->error($notFound->getMessage());

            return Command::FAILURE;
        }

        if (RoundState::Judged === $state) {
            $io->error('The round is judged, so its head is no longer under review.');

            return Command::FAILURE;
        }

        $io->writeln(sprintf('Review server of round %s: %s', $round, self::PATH));
        $io->writeln(sprintf('Authorization: Bearer %s', $this->credential->issueFor($round)), SymfonyStyle::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
