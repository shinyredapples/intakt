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

    // Linked under the next free number, which fails if the name exists, so two calls that start together never share a number and no reader sees a record before it is complete.
    public function callStarted(): int
    {
        $record = $this->record();

        try {
            for ($call = 1;; ++$call) {
                if ($this->publish($record, $this->file($call, 'start'))) {
                    return $call;
                }
            }
        } finally {
            unlink($record);
        }
    }

    public function callEnded(int $call): void
    {
        $record = $this->record();

        try {
            if (!$this->publish($record, $this->file($call, 'end'))) {
                throw new RuntimeException('The end of call '.$call.' was already recorded.');
            }
        } finally {
            unlink($record);
        }
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

    // Written in full under a name no reader globs for, so a reader never sees the record before it is complete.
    private function record(): string
    {
        if (!is_dir($this->path.'/calls') && !@mkdir($this->path.'/calls', 0o777, true) && !is_dir($this->path.'/calls')) {
            throw new RuntimeException('The script directory has no calls folder.');
        }

        $record = $this->path.'/calls/'.getmypid().'-'.bin2hex(random_bytes(6)).'.record';
        $process = RecordedProcess::current();
        $content = json_encode(['pid' => $process->pid, 'started' => $process->started, 'at' => MonotonicSeconds::now()], JSON_THROW_ON_ERROR);

        // Checked before it is linked, because a short write (a full disk, a file size limit) would publish a truncated record that stops every reader.
        if (strlen($content) !== file_put_contents($record, $content)) {
            @unlink($record);

            throw new RuntimeException('The call record '.$record.' could not be written in full.');
        }

        return $record;
    }

    private function publish(string $record, string $name): bool
    {
        if (@link($record, $name)) {
            return true;
        }

        return file_exists($name) ? false : throw new RuntimeException('The call record '.$name.' could not be published.');
    }

    private function file(int $call, string $kind): string
    {
        return $this->path.'/calls/'.$call.'.'.$kind;
    }
}
