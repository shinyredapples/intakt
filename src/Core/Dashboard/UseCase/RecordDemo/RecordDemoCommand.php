<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\RecordDemo;

use Composer\InstalledVersions;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
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

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The project of the review')]
        string $project,
        #[Argument('The change that was reviewed, as the review command names it')]
        string $change,
        #[Argument('The file the recording is written to')]
        string $recording,
    ): int {
        $rows = $this->rows->of($project, $change);

        if ([] === $rows->rounds) {
            $io->error(sprintf('No review round of change %s in project %s is in the ledger.', $change, $project));

            return Command::FAILURE;
        }

        try {
            $made = $this->recordingOf($rows, $project, $change);
            $this->filesystem->dumpFile($recording, $this->format->write($made));
        } catch (RecordingNeedsCapturedUpdates|IOException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Recorded %d ledger events and %d live updates of %d review rounds into %s.', count($made->events), count($made->updates), count($rows->rounds), $recording));

        return Command::SUCCESS;
    }

    private function recordingOf(ReviewRows $rows, string $project, string $change): Recording
    {
        $events = array_map(RecordedLedgerEvent::of(...), $rows->events);

        return new Recording(
            array_first($events)->recordedAt,
            $this->intaktVersion(),
            $rows->models,
            $project,
            $change,
            $events,
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
