<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\UseCase\ReadEvents;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:ledger:events', description: 'Read the ledger events back in the order they were stored')]
final readonly class ReadEventsCommand
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $count = 0;

        foreach ($this->ledger->readInOrder() as $event) {
            $io->writeln($this->lineOf($event));
            ++$count;
        }

        $io->writeln(sprintf('%d events', $count));

        return Command::SUCCESS;
    }

    private function lineOf(RecordedEvent $event): string
    {
        return sprintf('%s %s %s %s', $event->recordedAt->format('Y-m-d\TH:i:s\Z'), $event->id->toRfc4122(), $event->event->kind->value, $event->event->payload);
    }
}
