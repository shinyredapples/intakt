<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\ReadBuildUsage;

use Intakt\Core\Build\Domain\BuildUsage;
use Intakt\Core\Build\Domain\UsageReading;
use Intakt\Core\Build\UseCase\RunBuildJob\IsolatedSessionLog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:build:usage', description: 'Read the usage a build job\'s usage.log reports, in a separate process under the same limits as after a build')]
final readonly class ReadBuildUsageCommand
{
    private const int FILE_TYPE = 0o170000;

    private const int REGULAR_FILE = 0o100000;

    public function __construct(
        private IsolatedSessionLog $sessionLog,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] ReadBuildUsageInput $input): int
    {
        $checked = $this->quietly(fn (): ?array => $this->plainFileStatOf($input->log));

        if (null === $checked) {
            $io->error(sprintf('"%s" is not a regular file: a link, a folder, a named pipe or another special file is never read.', $input->log));

            return Command::FAILURE;
        }

        $log = $this->quietly(fn (): ?string => $this->contentOf($input->log, $checked));

        if (null === $log) {
            $io->error(sprintf('"%s" could not be read, or was replaced after it was checked.', $input->log));

            return Command::FAILURE;
        }

        $usage = $this->sessionLog->usageIn($log);

        return $usage->usage instanceof BuildUsage ? $this->printed($io, $usage->usage) : $this->unread($io, $usage->reading);
    }

    // Warnings silenced, because a path that went or turned unreadable after the check gives the command's own error rather than a warning.
    /**
     * @template T
     *
     * @param callable(): T $read
     *
     * @return T
     */
    private function quietly(callable $read): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $read();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return array<int|string, int>|null
     */
    private function plainFileStatOf(string $path): ?array
    {
        clearstatcache(true, $path);
        $checked = lstat($path);

        return false !== $checked && self::REGULAR_FILE === ($checked['mode'] & self::FILE_TYPE) ? $checked : null;
    }

    // Opened without waiting ('n'), so a named pipe put in the path's place after the check never holds the command before the reader's time limit starts.
    // Read only when the opened file is the one checked, so a path swapped for a link after the check is not followed to its target.
    // One byte past the limit is read and no more, so a log that grows after the check still never reaches memory whole.
    /**
     * @param array<int|string, int> $checked
     */
    private function contentOf(string $path, array $checked): ?string
    {
        $handle = fopen($path, 'rbn');

        if (false === $handle) {
            return null;
        }

        try {
            $opened = fstat($handle);

            if (false === $opened || $opened['dev'] !== $checked['dev'] || $opened['ino'] !== $checked['ino'] || self::REGULAR_FILE !== ($opened['mode'] & self::FILE_TYPE)) {
                return null;
            }

            $log = stream_get_contents($handle, IsolatedSessionLog::MAX_BYTES + 1);

            return false === $log ? null : $log;
        } finally {
            fclose($handle);
        }
    }

    private function printed(SymfonyStyle $io, BuildUsage $usage): int
    {
        $io->writeln('reading '.UsageReading::Read->value);

        foreach ($usage->counts() as $name => $count) {
            $io->writeln($name.' '.$count);
        }

        $io->writeln('total '.$usage->total());

        return Command::SUCCESS;
    }

    private function unread(SymfonyStyle $io, UsageReading $reading): int
    {
        $io->error('usage not read: '.$reading->value);

        return Command::FAILURE;
    }
}
