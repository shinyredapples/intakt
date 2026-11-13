<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Build\Contract\BuildBrief;
use Intakt\Core\Platform\Contract\ReportableFailure;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'intakt:build:run', description: 'Run a first build of a task in a build job with the Claude Code CLI, write the bundle it hands back and print its pull request text')]
final readonly class RunBuildJobCommand
{
    // The end of the log, because that is where a failed session says why.
    private const int LOG_LINES = 40;

    public function __construct(
        private RunBuildJob $builds,
        private Filesystem $filesystem,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] RunBuildJobInput $input): int
    {
        try {
            $outcome = $this->builds->run($this->jobOf($input));
        } catch (InvalidArgumentException|TierResolutionFailure|ReportableFailure|IOException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        if (!$outcome->built()) {
            return $this->failed($io, $outcome);
        }

        return $this->handedBack($io, $outcome, $input->bundle);
    }

    private function jobOf(RunBuildJobInput $input): BuildJob
    {
        $brief = new BuildBrief($this->filesystem->readFile($input->task), array_values($input->decision), null);

        return new BuildJob($input->project, $input->clone, $input->branch, $brief, $this->tierOf($input->tier), $input->image);
    }

    private function tierOf(string $value): Tier
    {
        return Tier::tryFrom($value) ?? throw new InvalidArgumentException(sprintf(
            'The tier "%s" is not one of %s.',
            $value,
            implode(', ', array_map(static fn (Tier $tier): string => $tier->value, Tier::cases())),
        ));
    }

    private function failed(SymfonyStyle $io, BuildJobOutcome $outcome): int
    {
        foreach ($this->endOfLog($outcome->log) as $line) {
            $io->writeln(OutputFormatter::escape($line));
        }

        $io->error('failed: '.$outcome->verdict->reason());

        return Command::FAILURE;
    }

    private function handedBack(SymfonyStyle $io, BuildJobOutcome $outcome, string $path): int
    {
        $this->filesystem->dumpFile($path, (string) $outcome->bundle);
        $io->writeln('bundle '.OutputFormatter::escape($path));
        $io->writeln('pull request text:');
        $io->write(OutputFormatter::escape((string) $outcome->pullRequestText));

        return Command::SUCCESS;
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
