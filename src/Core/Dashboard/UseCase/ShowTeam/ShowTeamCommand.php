<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Provider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'intakt:team', description: 'Print the configured accounts once, each with its state and the roles it serves, then the providers each role can run on')]
final readonly class ShowTeamCommand
{
    public function __construct(
        private TeamRoster $roster,
    ) {
    }

    public function __invoke(OutputInterface $output): int
    {
        $team = $this->roster->now();

        // Raw, because a reviewer role name is the owner's text and may look like console markup.
        foreach ($team->accounts as $account) {
            $output->writeln(sprintf('account %s: %s, serves %s', $account->account->key(), $account->state->value, $this->rolesOf($account)), OutputInterface::OUTPUT_RAW);
        }

        foreach ($team->roles as $role) {
            $output->writeln(sprintf('role %s: %s', $role->label(), $this->providersOf($role)), OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }

    private function rolesOf(TeamAccount $account): string
    {
        return [] === $account->roles ? 'no role' : implode(', ', array_map(static fn (TeamRole $role): string => $role->label(), $account->roles));
    }

    private function providersOf(TeamRole $role): string
    {
        return [] === $role->providers ? 'no provider' : implode(', ', array_map(static fn (Provider $provider): string => $provider->value.(in_array($provider, $role->notRunnable, true) ? ' (the build runtime cannot run it)' : ''), $role->providers));
    }
}
