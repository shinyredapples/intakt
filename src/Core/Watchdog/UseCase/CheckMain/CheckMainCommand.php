<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\CheckMain;

use Intakt\Core\Watchdog\Contract\Classification;
use Intakt\Core\Watchdog\Contract\CommitNameNotFull;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use Intakt\Core\Watchdog\Infrastructure\ConsoleReport;
use Intakt\Core\Watchdog\Infrastructure\OwnerKeyRefused;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessFailedException;

#[AsCommand(name: self::NAME, description: 'Check the new commits on main since the last seen head against the watchdog\'s rules')]
final readonly class CheckMainCommand
{
    public const string NAME = 'intakt:watchdog:check';

    public function __construct(
        private CheckMain $check,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] CheckMainInput $input): int
    {
        try {
            $classification = $this->check->classified($input->repository, $this->watched($input->lastSeenHead, $input->recordedMerge), $input->trustedKeys());
        } catch (CommitNameNotFull|RecordedMergeNotARange|OwnerKeyRefused $refused) {
            $io->error(ConsoleReport::printable($refused->getMessage()));

            return Command::INVALID;
        } catch (MainNotRead $notRead) {
            $io->error(ConsoleReport::printable($notRead->getMessage()));

            return Command::FAILURE;
        } catch (ProcessFailedException $failed) {
            $io->error(ConsoleReport::printable(MainNotRead::because('a git call failed: '.trim($failed->getProcess()->getErrorOutput()))->getMessage()));

            return Command::FAILURE;
        }

        return $this->reported($io, $classification);
    }

    /**
     * @param list<string> $recordedMerges
     */
    private function watched(string $lastSeenHead, array $recordedMerges): WatchedMain
    {
        return new WatchedMain($lastSeenHead, array_map(RecordedMergeOption::parsed(...), $recordedMerges));
    }

    private function reported(SymfonyStyle $io, Classification $classification): int
    {
        foreach ($classification->peerMerges as $commit) {
            $io->writeln(ConsoleReport::peerMergeLine($commit->name));
        }
        if ($classification->isClean()) {
            $io->writeln('main is clean');

            return Command::SUCCESS;
        }
        foreach ($classification->violations as $violation) {
            $io->writeln('violation '.$violation->commit.' '.$violation->violation->value);
        }

        return Command::FAILURE;
    }
}
