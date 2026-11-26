<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

// Raw output, because an account or role name holding console markup must reach the owner as it was configured.
#[AsCommand(name: 'intakt:team', description: 'Print every vendor account with its state and the roles it serves')]
final readonly class ShowTeamCommand
{
    public function __construct(
        private TeamRoster $roster,
    ) {
    }

    public function __invoke(OutputInterface $output): int
    {
        $accounts = $this->roster->now()->accounts;

        if ([] === $accounts) {
            $output->writeln('No account is listed.', OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        foreach ($accounts as $account) {
            $output->writeln($this->lineOf($account), OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }

    private function lineOf(TeamAccount $account): string
    {
        $roles = [] === $account->roles ? 'no role' : implode(', ', array_map(static fn (TeamRole $role): string => $role->inWords(), $account->roles));

        return sprintf('%s: %s: %s', $account->account->key(), $account->state->value, $roles);
    }
}
