<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

// Standard error because the log in the application has no handler yet that prints warnings.
final readonly class DisabledProvidersWarning
{
    /**
     * @param array<string, string> $disabledProviders
     */
    public function __construct(
        #[Autowire('%intakt.ai.disabled_providers%')]
        private array $disabledProviders,
    ) {
    }

    #[AsEventListener(event: ConsoleCommandEvent::class)]
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $output = $event->getOutput();
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        foreach ($this->disabledProviders as $message) {
            $errors->writeln('<comment>'.$message.'</comment>');
        }
    }
}
