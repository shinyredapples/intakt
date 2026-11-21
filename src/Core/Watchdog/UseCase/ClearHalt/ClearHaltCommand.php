<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\ClearHalt;

use Intakt\Core\Watchdog\Contract\OwnerCard;
use Intakt\Core\Watchdog\Infrastructure\ConsoleReport;
use Intakt\Core\Watchdog\Infrastructure\HaltNotRecorded;
use Intakt\Core\Watchdog\Infrastructure\LedgerHalts;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: self::NAME, description: 'Clear one owner card of a watchdog halt, after checking its commit; asks for the owner token')]
final readonly class ClearHaltCommand
{
    public const string NAME = 'intakt:watchdog:clear';

    public function __construct(
        private ClearHalt $clear,
        private LedgerHalts $halts,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The id of the owner card to clear, as intakt:watchdog:guard prints it')]
        string $card,
    ): int {
        try {
            $cleared = $this->clear->cleared($card, $this->ownerToken($io));
        } catch (OnlyTheOwnerClearsAHalt|OnlyAStandingCardIsCleared|HaltNotRecorded $refused) {
            $io->error(ConsoleReport::printable($refused->getMessage()));

            return Command::FAILURE;
        }

        $io->writeln('card '.$cleared->id->toRfc4122().' cleared');
        $this->reportStanding($io, $cleared);

        return Command::SUCCESS;
    }

    // Asked, never an argument or an option, because a command line shows in the process list and the shell's history.
    private function ownerToken(SymfonyStyle $io): string
    {
        $answer = $io->askQuestion(new Question('The owner token')->setHidden(true));

        return is_string($answer) ? $answer : '';
    }

    private function reportStanding(SymfonyStyle $io, OwnerCard $cleared): void
    {
        $standing = $this->halts->standingOf($cleared->project);
        if ([] === $standing) {
            $io->writeln(ConsoleReport::printable($cleared->project).' is not halted');

            return;
        }
        foreach ($standing as $card) {
            $io->writeln(ConsoleReport::cardLine($card));
        }
    }
}
