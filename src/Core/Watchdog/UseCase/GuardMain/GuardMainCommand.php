<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\GuardMain;

use Intakt\Core\Watchdog\Contract\CommitNameNotFull;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\OwnerCard;
use Intakt\Core\Watchdog\Infrastructure\ConsoleReport;
use Intakt\Core\Watchdog\Infrastructure\HaltNotRecorded;
use Intakt\Core\Watchdog\Infrastructure\NoticeNotRecorded;
use Intakt\Core\Watchdog\Infrastructure\OwnerKeyRefused;
use Intakt\Core\Watchdog\UseCase\CheckMain\RecordedMergeNotARange;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessFailedException;

#[AsCommand(name: self::NAME, description: 'Check main like intakt:watchdog:check and halt the project with an owner card for each violation, and give a once-only notice for the owner\'s own push')]
final readonly class GuardMainCommand
{
    public const string NAME = 'intakt:watchdog:guard';

    public function __construct(
        private GuardMain $guard,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] GuardMainInput $input): int
    {
        try {
            $guarded = $this->guard->guarded($input);
        } catch (AHaltNamesItsProject|CommitNameNotFull|RecordedMergeNotARange|OwnerKeyRefused $refused) {
            $io->error(ConsoleReport::printable($refused->getMessage()));

            return Command::INVALID;
        } catch (NoticeNotRecorded $failed) {
            $this->noticed($io, $input->project, $failed->givenBefore);
            $io->error(ConsoleReport::printable($failed->getMessage()));

            return Command::FAILURE;
        } catch (MainNotRead|HaltNotRecorded $failed) {
            $io->error(ConsoleReport::printable($failed->getMessage()));

            return Command::FAILURE;
        } catch (ProcessFailedException $failed) {
            $io->error(ConsoleReport::printable(MainNotRead::because('a git call failed: '.trim($failed->getProcess()->getErrorOutput()))->getMessage()));

            return Command::FAILURE;
        }

        $this->noticed($io, $input->project, $guarded->noticedOwnerPushes);

        return $this->reported($io, $input->project, $guarded->standingCards);
    }

    /**
     * @param list<string> $commits
     */
    private function noticed(SymfonyStyle $io, string $project, array $commits): void
    {
        foreach ($commits as $commit) {
            $io->writeln(OutputFormatter::escape(ConsoleReport::noticeLine($project, $commit)));
        }
    }

    /**
     * @param list<OwnerCard> $cards
     */
    private function reported(SymfonyStyle $io, string $project, array $cards): int
    {
        if ([] === $cards) {
            $io->writeln(OutputFormatter::escape(ConsoleReport::printable($project).' is not halted: no owner card stands'));

            return Command::SUCCESS;
        }
        foreach ($cards as $card) {
            $io->writeln(OutputFormatter::escape(ConsoleReport::cardLine($card)));
        }

        return Command::FAILURE;
    }
}
