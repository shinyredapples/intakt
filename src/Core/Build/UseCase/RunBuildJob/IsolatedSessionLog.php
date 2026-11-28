<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Domain\SessionLog;
use Intakt\Core\Build\Domain\SessionUsage;
use Intakt\Core\Build\Domain\UsageReading;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

// In a child process with no environment and its own memory and time limits, because the log is the builder's to write: a crafted one must not cost the orchestrator its memory or time, nor reach its credentials (docs/design/05-architecture/06-budget-and-model-planner.md).
final readonly class IsolatedSessionLog
{
    // Checked before the reader starts, because a log is the builder's to fill and the reader's memory must stay bounded whatever it wrote.
    public const int MAX_BYTES = 4 * 1024 * 1024;

    public const string MEMORY_LIMIT = '64M';

    // Parsing the largest allowed log takes well under a second, so a reader still running after this was made to hang.
    public const float SECONDS = 5.0;

    public function __construct(
        // The application's autoloader without its kernel, so the reader loads no .env file and boots no service.
        #[Autowire('%kernel.project_dir%/vendor/autoload.php')]
        private string $autoload,
    ) {
    }

    public function usageIn(string $log): SessionUsage
    {
        if (strlen($log) > self::MAX_BYTES) {
            return SessionUsage::withoutUsage(UsageReading::TooLarge);
        }

        $finder = new PhpExecutableFinder();
        $php = $finder->find(false);

        if (false === $php) {
            return SessionUsage::withoutUsage(UsageReading::ReaderFailed);
        }

        return $this->readBy([$php, ...$finder->findArguments()], $log);
    }

    /**
     * @param list<string> $php
     */
    private function readBy(array $php, string $log): SessionUsage
    {
        // Built inside the attempt, because without proc_open the constructor already throws.
        try {
            $reader = new Process([...$php, '-d', 'memory_limit='.self::MEMORY_LIMIT, '-r', $this->code(), '--', $this->autoload], null, $this->noEnvironment(), $log, self::SECONDS);
            $reader->run();
        } catch (ExceptionInterface) {
            return SessionUsage::withoutUsage(UsageReading::ReaderFailed);
        }

        return $reader->isSuccessful() ? SessionUsage::fromAnswer($reader->getOutput()) : SessionUsage::withoutUsage(UsageReading::ReaderFailed);
    }

    private function code(): string
    {
        return sprintf('require $argv[1]; echo %s::usageIn(stream_get_contents(STDIN))->answer();', SessionLog::class);
    }

    // Every variable the child would inherit is unset, because the orchestrator's environment holds its credentials.
    /**
     * @return array<string, false>
     */
    private function noEnvironment(): array
    {
        return array_fill_keys(array_map(strval(...), array_keys($_ENV + getenv())), false);
    }
}
