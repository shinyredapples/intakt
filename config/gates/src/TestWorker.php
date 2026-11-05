<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class TestWorker
{
    public const string PORTS_VARIABLE = 'INTAKT_TEST_PORTS';
    public const string GATES_CACHE_VARIABLE = 'INTAKT_GATES_CACHE';

    public function __construct(public string $run, private string $directory, private string $ports, public string $configuration, public string $selection)
    {
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
