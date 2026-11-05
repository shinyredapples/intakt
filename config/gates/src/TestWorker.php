<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class TestWorker
{
    public const string PORTS_VARIABLE = 'INTAKT_TEST_PORTS';
    public const string GATES_CACHE_VARIABLE = 'INTAKT_GATES_CACHE';

    // Because Linux hands out port zero from 32768 up, so a port the system picks for a server never lands in a worker's block.
    private const int FIRST_PORT = 20000;
    private const int PORTS_PER_WORKER = 100;

    private function __construct(public string $run, private string $directory, private int $index)
    {
    }

    public static function numbered(string $scratch, string $pass, int $index): self
    {
        return new self($pass, $scratch.'/'.$pass.'/worker-'.$index, $index);
    }

    // Because the serial pass starts only after every worker has stopped, the ports of the first worker are free again.
    public static function serial(string $scratch, string $pass): self
    {
        return new self($pass.' serial', $scratch.'/'.$pass.'/serial', 0);
    }

    public function prepared(): bool
    {
        return mkdir($this->directory.'/tmp', 0o700, true);
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        $firstPort = self::FIRST_PORT + $this->index * self::PORTS_PER_WORKER;

        return [
            'DATABASE_URL' => 'sqlite:///'.$this->directory.'/database.sqlite',
            'TMPDIR' => $this->directory.'/tmp',
            'APP_CACHE_DIR' => $this->directory.'/cache',
            'APP_LOG_DIR' => $this->directory.'/log',
            self::GATES_CACHE_VARIABLE => $this->directory.'/tools',
            self::PORTS_VARIABLE => $firstPort.'-'.($firstPort + self::PORTS_PER_WORKER - 1),
        ];
    }

    public function junitReport(): string
    {
        return $this->directory.'/junit.xml';
    }
}
