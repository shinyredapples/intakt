<?php

declare(strict_types=1);

namespace Intakt\Core\Doctor\UseCase\RunDoctor;

use Intakt\Core\Doctor\Contract\CheckResult;
use Intakt\Core\Doctor\Contract\DoctorServices;
use Intakt\Core\Doctor\Contract\HealthCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

// A command is the door because the owner needs it when the dashboard does not start.
#[AsCommand(name: 'intakt:doctor', description: 'Check the install and say what to do about each problem (read-only)')]
final readonly class DoctorCommand
{
    /**
     * @param iterable<HealthCheck> $checks
     */
    public function __construct(
        #[AutowireIterator(DoctorServices::CHECK_TAG)]
        private iterable $checks,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $failures = 0;
        foreach ($this->checks as $check) {
            $result = $this->resultOf($check);
            $io->writeln($this->line($check, $result));
            $failures += $result->passed ? 0 : 1;
        }

        return 0 === $failures ? Command::SUCCESS : Command::FAILURE;
    }

    private function resultOf(HealthCheck $check): CheckResult
    {
        try {
            return $check->run();
        } catch (Throwable $failure) {
            // Only the class, because a thrown message can echo a credential.
            return CheckResult::failed('The check could not run ('.$failure::class.'). Start the stack, then run it again.');
        }
    }

    private function line(HealthCheck $check, CheckResult $result): string
    {
        return $result->passed ? 'pass: '.$this->oneLine($check->name()) : 'FAIL: '.$this->oneLine($check->name()).': '.$this->oneLine($result->fix);
    }

    // One line per check is the promise scripts rely on, and a fix text can quote what the forge sent, line breaks included.
    private function oneLine(string $text): string
    {
        return preg_replace('/\p{Cc}/u', ' ', $text) ?? preg_replace('/[\x00-\x1F\x7F]/', ' ', $text) ?? '';
    }
}
