<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class TestWorker
{
    public const string PORTS_VARIABLE = 'INTAKT_TEST_PORTS';
    public const string GATES_CACHE_VARIABLE = 'INTAKT_GATES_CACHE';

    private function __construct(public string $run, private string $directory, private string $ports)
    {
    }

    public static function numbered(string $scratch, string $pass, int $index, TestPorts $ports): self
    {
        return new self($pass, $scratch.'/'.$pass.'/worker-'.$index, $ports->block($index));
    }

    // Because the serial pass starts only after every worker has stopped, the ports of the first worker are free again.
    public static function serial(string $scratch, string $pass, TestPorts $ports): self
    {
        return new self($pass.' serial', $scratch.'/'.$pass.'/serial', $ports->block(0));
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
        return [
            'DATABASE_URL' => 'sqlite:///'.$this->directory.'/database.sqlite',
            'TMPDIR' => $this->directory.'/tmp',
            'APP_CACHE_DIR' => $this->directory.'/cache',
            'APP_LOG_DIR' => $this->directory.'/log',
            'APP_SHARE_DIR' => $this->directory.'/share',
            self::GATES_CACHE_VARIABLE => $this->directory.'/tools',
            self::PORTS_VARIABLE => $this->ports,
        ];
    }

    public function junitReport(): string
    {
        return $this->directory.'/junit.xml';
    }
}
