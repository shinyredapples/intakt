<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\CheckMain;

use Intakt\Core\Watchdog\Contract\Classification;
use Intakt\Core\Watchdog\Contract\CommitNameNotFull;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use Intakt\Core\Watchdog\Infrastructure\OwnerKeyRefused;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessFailedException;

#[AsCommand(name: 'intakt:watchdog:check', description: 'Check the new commits on main since the last seen head against the watchdog\'s rules')]
final readonly class CheckMainCommand
{
    public function __construct(
        private CheckMain $check,
    ) {
    }

    /**
     * @param list<string> $recordedMerge
     * @param list<string> $ownerKey
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The repository whose main branch is checked: the top folder of a checkout, or a bare repository')]
        string $repository,
        #[Argument(description: 'The full commit name of the head of main the watchdog saw last')]
        string $lastSeenHead,
        #[Option(description: 'A merge Intakt recorded, as <base>..<head> with full commit names (repeatable)')]
        array $recordedMerge = [],
        #[Option(description: 'An SSH public key the owner registered, "<type> <base64>" (repeatable)')]
        array $ownerKey = [],
    ): int {
        try {
            $classification = $this->check->classified($repository, $this->watched($lastSeenHead, $recordedMerge), $ownerKey);
        } catch (CommitNameNotFull|RecordedMergeNotARange|OwnerKeyRefused $refused) {
            $io->error($this->printable($refused->getMessage()));

            return Command::INVALID;
        } catch (MainNotRead $notRead) {
            $io->error($this->printable($notRead->getMessage()));

            return Command::FAILURE;
        } catch (ProcessFailedException $failed) {
            $io->error($this->printable(MainNotRead::because('a git call failed: '.trim($failed->getProcess()->getErrorOutput()))->getMessage()));

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
        if ($classification->isClean()) {
            $io->writeln('main is clean');

            return Command::SUCCESS;
        }
        foreach ($classification->violations as $violation) {
            $io->writeln('violation '.$violation->commit.' '.$violation->violation->value);
        }

        return Command::FAILURE;
    }

    private function printable(string $text): string
    {
        // Because a path or git's output can hold a newline or an escape sequence, which would forge a line of this report or steer the terminal; the error block escapes formatter tags itself.
        return addcslashes($text, "\0..\37\177\\");
    }
}
