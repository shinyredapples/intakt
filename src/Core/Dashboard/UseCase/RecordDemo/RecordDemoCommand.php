<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\RecordDemo;

use Composer\InstalledVersions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'intakt:demo:record', description: 'Record the ledger events and the captured live updates of a change\'s review rounds into one file')]
final readonly class RecordDemoCommand
{
    public function __construct(
        private ReviewRowsOfChange $rows,
        private CapturedUpdates $captured,
        private RecordingFormat $format,
        private Filesystem $filesystem,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] RecordDemoInput $input): int
    {
        $rows = $this->rows->of($input->project, $input->change);

        if (!$rows instanceof ReviewRows) {
            $io->error(sprintf('No review round of change %s in project %s is in the ledger.', $input->change, $input->project));

            return Command::FAILURE;
        }

        try {
            $recording = $this->recordingOf($rows, $input);
            $this->filesystem->dumpFile($input->recording, $this->format->write($recording));
        } catch (RecordingNeedsCapturedUpdates|IOException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Recorded %d ledger events and %d live updates of %d review rounds into %s.', count($recording->events), count($recording->updates), count($rows->rounds), $input->recording));

        return Command::SUCCESS;
    }

    private function recordingOf(ReviewRows $rows, RecordDemoInput $input): Recording
    {
        return new Recording(
            $rows->startedAt,
            $this->intaktVersion(),
            $rows->models,
            $input->project,
            $input->change,
            array_map(RecordedLedgerEvent::of(...), $rows->events),
            $this->captured->ofRounds($rows->rounds),
        );
    }

    // The commit beside the version when Composer knows it, because a checkout of main is always the version dev-main.
    private function intaktVersion(): string
    {
        $root = InstalledVersions::getRootPackage();

        return null === $root['reference'] ? $root['pretty_version'] : $root['pretty_version'].'@'.$root['reference'];
    }
}
