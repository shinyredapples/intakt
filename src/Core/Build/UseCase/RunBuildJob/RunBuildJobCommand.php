<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Build\Contract\BuildBrief;
use Intakt\Core\Build\Contract\Decisions;
use Intakt\Core\Build\Domain\BuildObservation;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Platform\Contract\ReportableFailure;
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

    // Listed by hand, because PCRE has no class for the property; the ranges are Unicode's Default_Ignorable_Code_Point from DerivedCoreProperties.txt (Unicode 16.0).
    /**
     * @var list<array{int, int}>
     */
    public const array DEFAULT_IGNORABLE = [
        [0x00AD, 0x00AD], [0x034F, 0x034F], [0x061C, 0x061C], [0x115F, 0x1160], [0x17B4, 0x17B5], [0x180B, 0x180F],
        [0x200B, 0x200F], [0x202A, 0x202E], [0x2060, 0x206F], [0x3164, 0x3164], [0xFE00, 0xFE0F], [0xFEFF, 0xFEFF],
        [0xFFA0, 0xFFA0], [0xFFF0, 0xFFF8], [0x1BCA0, 0x1BCA3], [0x1D173, 0x1D17A], [0xE0000, 0xE0FFF],
    ];

    public function __construct(
        private RunBuildJob $builds,
        private Filesystem $filesystem,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] RunBuildJobInput $input): int
    {
        try {
            $outcome = $this->builds->run($this->jobOf($input));
        } catch (TierResolutionFailure|ReportableFailure|JobRefused|IOException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $this->observed($io, $outcome->observation);

        if ($outcome->question instanceof BuildQuestion) {
            return $this->asked($io, $outcome->question);
        }

        if (!$outcome->built()) {
            return $this->failed($io, $outcome);
        }

        return $this->handedBack($io, $outcome, $input->bundle);
    }

    private function jobOf(RunBuildJobInput $input): BuildJob
    {
        $brief = new BuildBrief($this->filesystem->readFile($input->task), new Decisions(...$input->decision), null);

        return new BuildJob($input->project, $input->clone, $input->branch, $brief, $this->tierOf($input->tier), $input->image);
    }

    private function tierOf(string $value): Tier
    {
        return Tier::tryFrom($value) ?? throw TierNotKnown::named($value);
    }

    // Only when verbose, because the other output is what the build handed back; every figure printed is a number, a time, a hash or a word of this code's own.
    private function observed(SymfonyStyle $io, ?BuildObservation $observation): void
    {
        if (!$io->isVerbose() || !$observation instanceof BuildObservation) {
            return;
        }

        $io->writeln(sprintf('observed at %s: %d tokens (trusted: the CLI\'s report)', $observation->trusted->observedAt->format(DATE_ATOM), $observation->trusted->tokensUsed));
        $io->writeln(sprintf(
            'builder\'s figures (untrusted): content %s, %d failing tests, check running: %s',
            '' === $observation->untrusted->contentFingerprint ? 'none' : $observation->untrusted->contentFingerprint,
            count($observation->untrusted->failingTests),
            $observation->untrusted->checkRunning ? 'yes' : 'no',
        ));
    }

    private function asked(SymfonyStyle $io, BuildQuestion $question): int
    {
        $io->writeln('question: '.$this->oneLine($question->question));

        foreach ($question->choices as $choice) {
            $io->writeln('choice: '.$this->oneLine($choice));
        }

        $io->writeln('recommendation: '.$this->oneLine($question->recommendation));

        return Command::SUCCESS;
    }

    private function failed(SymfonyStyle $io, BuildJobOutcome $outcome): int
    {
        foreach ($this->endOfLog($outcome->log) as $line) {
            $io->writeln(OutputFormatter::escape($line));
        }

        $io->error('failed: '.$outcome->verdict->reason());

        return Command::FAILURE;
    }

    // The builder wrote the text, so a line break or control could forge a line and an invisible character hide a difference; each is printed as its code point and a backslash doubled, so two different choices never print the same.
    private function oneLine(string $text): string
    {
        $ignorable = implode('', array_map(static fn (array $range): string => sprintf('\x{%X}-\x{%X}', ...$range), self::DEFAULT_IGNORABLE));
        $visible = preg_replace_callback(
            '/[\\\\\p{C}\p{Zl}\p{Zp}'.$ignorable.']|(?! )\p{Zs}/u',
            static fn (array $found): string => '\\' === $found[0] ? '\\\\' : sprintf('\u{%X}', mb_ord($found[0], 'UTF-8')),
            $text,
        );

        // A text the pattern cannot read is no UTF-8, so it is printed byte by byte.
        return OutputFormatter::escape($visible ?? '\x{'.bin2hex($text).'}');
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
