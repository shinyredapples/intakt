<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Infrastructure;

use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMainCommand;
use Intakt\Core\Watchdog\UseCase\GuardMain\GuardMainCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

// Console refuses an option without its value or a missing argument before the check runs, with exit 1, and the watchdog's caller must tell refused input (2) from a failed or violating check (1); Console's error listener logs the whole command line, and refused input was never checked, so an --owner-key or --peer-key value in it may be private key material.
final readonly class ExitInvalidOnRefusedUsage
{
    #[AsEventListener]
    public function __invoke(ConsoleErrorEvent $event): void
    {
        if (!$this->isTheCheck($event) || !$event->getError() instanceof ExceptionInterface) {
            return;
        }
        $event->setExitCode(Command::INVALID);
        $event->stopPropagation();
    }

    #[AsEventListener]
    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        if ($this->isTheCheck($event) && Command::INVALID === $event->getExitCode()) {
            $event->stopPropagation();
        }
    }

    private function isTheCheck(ConsoleEvent $event): bool
    {
        return in_array($event->getCommand()?->getName(), [CheckMainCommand::NAME, GuardMainCommand::NAME], true);
    }
}
