<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use RuntimeException;

// The files a scripted provider call and its journey share: one start and one end file per call, and the two files that decide how long a call is held.
final readonly class ScriptDirectory
{
    public const string RELEASE = 'release';
    public const string HOLD = 'hold';
    public const string WAIT_FOR_STARTS = 'wait-for-starts';
    // Under the journey's 240 seconds, so a call nobody releases ends on its own and a failed journey leaves no process waiting.
    private const float HOLD_SECONDS = 90.0;
    private const int POLL_MICROSECONDS = 20000;
    private const int PARTS = 2;

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

            fwrite($file, json_encode(['pid' => getmypid(), 'at' => microtime(true)], JSON_THROW_ON_ERROR));
            fclose($file);

            return $call;
        }
    }

    public function callEnded(int $call): void
    {
        file_put_contents($this->file($call, 'end'), json_encode(['pid' => getmypid(), 'at' => microtime(true)], JSON_THROW_ON_ERROR));
    }

    public function holdWhileScripted(): void
    {
        if (!is_file($this->path.'/'.self::HOLD)) {
            return;
        }

        $until = microtime(true) + self::HOLD_SECONDS;

        while (microtime(true) < $until && !$this->released()) {
            usleep(self::POLL_MICROSECONDS);
        }
    }

    private function released(): bool
    {
        if (is_file($this->path.'/'.self::RELEASE)) {
            return true;
        }

        $wanted = @file_get_contents($this->path.'/'.self::WAIT_FOR_STARTS);

        return false !== $wanted && count(glob($this->path.'/calls/*.start') ?: []) >= (int) $wanted;
    }

    private function file(int $call, string $kind): string
    {
        return $this->path.'/calls/'.$call.'.'.$kind;
    }
}
