<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\CreateDemoProject;

use Intakt\Core\Review\Contract\FlagProjectAsReplay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

// Only on the compose demo, because a replay never counts as a verdict and an owner's real install must not grow a project whose reviews nobody gave.
#[AsCommand(name: 'intakt:demo:create', description: 'Create the demo project that recorded reviews are replayed into, marked as a replay; only on the compose demo')]
final readonly class CreateDemoProjectCommand
{
    // Its own name beside the sample project, so a replay never mixes with the reviews a visitor runs on the sample change.
    public const string NAME = 'demo-replay';

    public function __construct(
        private MessageBusInterface $commands,
        #[Autowire(env: 'INTAKT_COMPOSE_SERVICE')]
        private string $composeService,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if ('' === $this->composeService) {
            $io->error('The demo project is created only on the compose demo, and INTAKT_COMPOSE_SERVICE is empty on this install.');

            return Command::FAILURE;
        }

        $this->commands->dispatch(new FlagProjectAsReplay(self::NAME));
        $io->success(sprintf('The demo project %s is marked as a replay: its reviews never count as a verdict.', self::NAME));

        return Command::SUCCESS;
    }
}
