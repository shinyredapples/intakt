<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\HandBack\Contract\CheckJob;
use Intakt\Core\HandBack\Contract\CheckJobOutcome;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:check:run', description: 'Check a build\'s bundle, then run the project\'s check command on it in a check job with a fresh clone and a fresh dependency cache')]
final readonly class RunCheckJobCommand
{
    // The end of the log, because that is where a failing check says why.
    private const int LOG_LINES = 40;

    public function __construct(
        private RunCheckJob $checks,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] RunCheckJobInput $input): int
    {
        try {
            $outcome = $this->checks->run($this->jobOf($input));
        } catch (ReportableFailure|JobRefused $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        if ($outcome instanceof RefusedHandBack) {
            $io->error('refused: '.$outcome->reason());

            return Command::FAILURE;
        }

        return $this->reported($io, $outcome);
    }

    private function jobOf(RunCheckJobInput $input): CheckJob
    {
        return new CheckJob(
            // No mounted credentials, because the check job takes none; the secret scan still runs.
            new HandBack($input->repository, $input->branch, $input->bundle, $input->mergeMain, new JobCredentials()),
            $input->image,
            $input->checkCommand,
            new Paths(...$input->rewritten),
        );
    }

    private function reported(SymfonyStyle $io, CheckJobOutcome $outcome): int
    {
        foreach ($outcome->lostContent as $lost) {
            $io->writeln(sprintf('lost %s: %d lines to %d', OutputFormatter::escape($lost->path), $lost->linesBefore, $lost->linesAfter));
        }

        foreach ($this->endOfLog($outcome->log) as $line) {
            $io->writeln(OutputFormatter::escape($line));
        }

        if ($outcome->passed()) {
            $io->writeln('passed');

            return Command::SUCCESS;
        }

        $io->error('failed: '.$outcome->verdict->reason().(null === $outcome->exitCode ? '' : sprintf(' (exit code %d)', $outcome->exitCode)));

        return Command::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function endOfLog(string $log): array
    {
        $log = rtrim($log, "\n");

        return '' === $log ? [] : array_slice(explode("\n", $log), -self::LOG_LINES);
    }
}
