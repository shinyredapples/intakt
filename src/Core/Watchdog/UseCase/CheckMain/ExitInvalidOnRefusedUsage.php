<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\CheckMain;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

// Console refuses an option without its value or a missing argument before the check runs, with exit 1; the watchdog's caller must tell refused input (2) from a failed or violating check (1).
#[AsEventListener]
final readonly class ExitInvalidOnRefusedUsage
{
    public function __invoke(ConsoleErrorEvent $event): void
    {
        if (CheckMainCommand::NAME !== $event->getCommand()?->getName() || !$event->getError() instanceof ExceptionInterface) {
            return;
        }
        $event->setExitCode(Command::INVALID);
    }
}
