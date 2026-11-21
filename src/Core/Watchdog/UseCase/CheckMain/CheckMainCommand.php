<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\CheckMain;

use Intakt\Core\Watchdog\Contract\Classification;
use Intakt\Core\Watchdog\Contract\CommitNameNotFull;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\WatchedMain;
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

    private const string UNICODE_CONTROLS = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';

    public function __construct(
        private CheckMain $check,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] CheckMainInput $input): int
    {
        try {
            $classification = $this->check->classified($input->repository, $this->watched($input->lastSeenHead, $input->recordedMerge), $input->ownerKey);
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
        $escaped = addcslashes($text, "\0..\37\177\\");

        // Unicode's own controls and line breaks (a next line, a line separator, a direction override) do the same; text that is not UTF-8 has every byte above ASCII escaped, because no character can be told apart in it.
        return preg_replace_callback(self::UNICODE_CONTROLS, static fn (array $match): string => sprintf('\u{%04X}', mb_ord($match[0], 'UTF-8')), $escaped) ?? addcslashes($escaped, "\200..\377");
    }
}
