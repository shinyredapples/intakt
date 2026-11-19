<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use RuntimeException;

// Files, because a scripted call runs in a worker process of the consumer, which the journey can reach only through them: one start and one end file per call, and the files that decide how long a call is held and how heavy it leaves its process.
final readonly class ScriptDirectory
{
    public const string RELEASE = 'release';
    public const string HOLD = 'hold';
    public const string WEIGHT = 'weight';
    // Under the journey's 240 seconds, so a call nobody releases ends on its own and a failed journey leaves no process waiting.
    private const float HOLD_SECONDS = 90.0;
    private const int POLL_MICROSECONDS = 20000;

    public function __construct(private string $path)
    {
    }

    // Created exclusively, so two calls that start together never share a number.
    public function callStarted(): int
    {
        if (!is_dir($this->path.'/calls') && !@mkdir($this->path.'/calls', 0o777, true) && !is_dir($this->path.'/calls')) {
            throw new RuntimeException('The script directory has no calls folder.');
        }

        for ($call = 1;; ++$call) {
            $file = @fopen($this->file($call, 'start'), 'x');

            if (false === $file) {
                continue;
            }

            fwrite($file, json_encode(['pid' => getmypid(), 'at' => MonotonicSeconds::now()], JSON_THROW_ON_ERROR));
            fclose($file);

            return $call;
        }
    }

    public function callEnded(int $call): void
    {
        file_put_contents($this->file($call, 'end'), json_encode(['pid' => getmypid(), 'at' => MonotonicSeconds::now()], JSON_THROW_ON_ERROR));
    }

    public function holdWhileScripted(int $call): void
    {
        if (!is_file($this->path.'/'.self::HOLD)) {
            return;
        }

        $until = MonotonicSeconds::now() + self::HOLD_SECONDS;

        while (MonotonicSeconds::now() < $until && !$this->released($call)) {
            usleep(self::POLL_MICROSECONDS);
        }
    }

    // One call at a time, because two reviewers released together write the ledger at the same instant, which a SQLite database can refuse to one of them.
    public function release(int $call): void
    {
        touch($this->file($call, self::RELEASE));
    }

    public function releaseAll(): void
    {
        touch($this->path.'/'.self::RELEASE);
    }

    public function released(int $call): bool
    {
        return is_file($this->path.'/'.self::RELEASE) || is_file($this->file($call, self::RELEASE));
    }

    // What a call leaves behind in its process (the megabytes the weight file names), so a parallel worker crosses the consumer's memory limit; nothing without the file.
    public function weightInBytes(): int
    {
        $megabytes = @file_get_contents($this->path.'/'.self::WEIGHT);

        return false === $megabytes ? 0 : max(0, (int) $megabytes) * 1024 * 1024;
    }

    private function file(int $call, string $kind): string
    {
        return $this->path.'/calls/'.$call.'.'.$kind;
    }
}
