<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\AiServices;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

// Standard error because the log in the application has no handler yet that prints warnings.
final readonly class DisabledProvidersWarning
{
    /**
     * @param array<string, string> $disabledProviders
     * @param list<string>          $disabledAccounts
     */
    public function __construct(
        #[Autowire(param: AiServices::DISABLED_PROVIDERS)]
        private array $disabledProviders,
        #[Autowire(param: AiServices::DISABLED_ACCOUNTS)]
        private array $disabledAccounts,
    ) {
    }

    #[AsEventListener(event: ConsoleCommandEvent::class)]
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $output = $event->getOutput();
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        foreach ([...array_values($this->disabledProviders), ...$this->disabledAccounts] as $message) {
            $errors->writeln('<comment>'.$message.'</comment>');
        }
    }
}
