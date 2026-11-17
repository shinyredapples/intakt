<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ReplayDemo;

use Intakt\Core\Dashboard\UseCase\CreateDemoProject\CreateDemoProjectCommand;
use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingFormat;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingIsMalformed;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

// Only on the compose demo, because the demo project exists only there and a replay must never write rows into an owner's real install.
#[AsCommand(name: 'intakt:demo:replay', description: 'Replay a recording\'s ledger events into the demo project at the recorded pace, sped up by a factor; only on the compose demo')]
final readonly class ReplayDemoCommand
{
    public function __construct(
        private ReplaysRecording $replays,
        private RecordingFormat $format,
        private Filesystem $filesystem,
        #[Autowire(env: 'INTAKT_COMPOSE_SERVICE')]
        private string $composeService,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] ReplayDemoInput $input): int
    {
        if ('' === $this->composeService) {
            $io->error('A recording is replayed only on the compose demo, and INTAKT_COMPOSE_SERVICE is empty on this install.');

            return Command::FAILURE;
        }

        if (!is_finite($input->factor) || $input->factor <= 0.0) {
            $io->error('The factor must be a number above 0.');

            return Command::FAILURE;
        }

        try {
            $recording = $this->format->read($this->filesystem->readFile($input->recording));
            $this->replays->replay($recording, $input->factor);
        } catch (IOException|RecordingIsMalformed|ReplayIsRefused|ReplayStopped $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->success($this->summaryOf($recording, $input->factor));

        return Command::SUCCESS;
    }

    private function summaryOf(Recording $recording, float $factor): string
    {
        return sprintf('Replayed %d ledger events of change %s into the demo project %s, %s times as fast as recorded.', count($recording->events), $recording->change, CreateDemoProjectCommand::NAME, $factor);
    }
}
