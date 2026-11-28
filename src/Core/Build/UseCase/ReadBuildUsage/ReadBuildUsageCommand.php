<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\ReadBuildUsage;

use Intakt\Core\Build\Domain\BuildUsage;
use Intakt\Core\Build\Domain\SessionUsage;
use Intakt\Core\Build\Domain\UsageReading;
use Intakt\Core\Build\UseCase\RunBuildJob\IsolatedSessionLog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'intakt:build:usage', description: 'Read the usage a build job\'s build.log reports, in a separate process under the same limits as after a build')]
final readonly class ReadBuildUsageCommand
{
    public function __construct(
        private IsolatedSessionLog $sessionLog,
        private Filesystem $filesystem,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] ReadBuildUsageInput $input): int
    {
        if (!$this->isPlainFile($input->log)) {
            $io->error(sprintf('"%s" is not a regular file: a link, a folder or another special file is never read.', $input->log));

            return Command::FAILURE;
        }

        $usage = $this->usageIn($input->log);

        if (!$usage instanceof SessionUsage) {
            $io->error(sprintf('"%s" could not be read.', $input->log));

            return Command::FAILURE;
        }

        return $usage->usage instanceof BuildUsage ? $this->printed($io, $usage->usage) : $this->unread($io, $usage->reading);
    }

    private function isPlainFile(string $path): bool
    {
        return !is_link($path) && is_file($path);
    }

    // The size is checked before the file is read, so a huge log never reaches memory.
    private function usageIn(string $path): ?SessionUsage
    {
        $size = filesize($path);

        if (false === $size) {
            return null;
        }

        if ($size > IsolatedSessionLog::MAX_BYTES) {
            return SessionUsage::withoutUsage(UsageReading::TooLarge);
        }

        try {
            return $this->sessionLog->usageIn($this->filesystem->readFile($path));
        } catch (IOException) {
            return null;
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
