<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Budget\Contract\AccountPick;
use Intakt\Core\Budget\Contract\LeftOutAccount;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

// Raw output, because an account or role name holding console markup must reach the owner as it was configured.
#[AsCommand(name: 'intakt:team', description: 'Print every vendor account with its state and the roles it serves, then each provider\'s last account pick')]
final readonly class ShowTeamCommand
{
    public function __construct(
        private TeamRoster $roster,
    ) {
    }

    public function __invoke(OutputInterface $output): int
    {
        $team = $this->roster->now();

        if ([] === $team->accounts) {
            $output->writeln('No account is listed.', OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        foreach ($team->accounts as $account) {
            $output->writeln($this->lineOf($account), OutputInterface::OUTPUT_RAW);
        }

        foreach ($team->picks as $pick) {
            $output->writeln($this->pickLineOf($pick), OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }

    private function lineOf(TeamAccount $account): string
    {
        $roles = [] === $account->roles ? 'no role' : implode(', ', array_map(static fn (TeamRole $role): string => $role->inWords(), $account->roles));

        return sprintf('%s: %s: %s', $account->account->key(), $account->state->value, $roles);
    }

    private function pickLineOf(AccountPick $pick): string
    {
        $picked = null === $pick->picked ? 'no account could start, earliest reset '.self::moment($pick->earliestReset()) : 'picked '.$pick->picked->key();
        $leftOut = [] === $pick->leftOut ? 'none' : implode(', ', array_map(static fn (LeftOutAccount $left): string => sprintf('%s (%s until %s)', $left->account->key(), $left->cause->value, self::moment($left->resetsAt)), $pick->leftOut));

        return sprintf('Last pick for %s at %s: %s; left out: %s', $pick->provider->value, self::moment($pick->pickedAt), $picked, $leftOut);
    }

    private static function moment(?DateTimeImmutable $moment): string
    {
        return $moment?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i \U\T\C') ?? 'unknown';
    }
}
