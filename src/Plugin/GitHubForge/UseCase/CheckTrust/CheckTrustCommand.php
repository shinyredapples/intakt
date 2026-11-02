<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\UseCase\CheckTrust;

use Intakt\Core\Platform\Contract\ReportableFailure;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringTrustCheck;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

// A command is the door because no watcher reads labels or comments yet; it shows the decision those events will get.
#[AsCommand(name: 'intakt:forge:trust', description: 'Show whether a GitHub account may steer Intakt on a repository')]
final readonly class CheckTrustCommand
{
    public function __construct(
        private SteeringTrustCheck $trust,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The repository, as owner/name')]
        string $repository,
        #[Argument('The GitHub login of the account')]
        string $account,
    ): int {
        try {
            $trust = $this->trust->check($repository, $account);
        } catch (ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->writeln(sprintf('%s on %s: %s, %s', $account, $repository, $trust->value, $trust->steers() ? 'steers' : 'does not steer'));

        return Command::SUCCESS;
    }
}
