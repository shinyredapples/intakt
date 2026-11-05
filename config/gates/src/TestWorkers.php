<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class TestWorkers
{
    private const string SERIAL_GROUP = 'serial';

    public function __construct(public string $scratch, private TestPorts $ports, public int $count)
    {
    }

    public function numbered(string $pass, string $configuration, int $index): TestWorker
    {
        return new TestWorker($pass, $this->scratch.'/'.$pass.'/worker-'.$index, $this->ports->block($index), $configuration, '--exclude-group='.self::SERIAL_GROUP);
    }

    /**
     * @return list<TestWorker>
     */
    public function allOf(string $pass, string $configuration): array
    {
        return array_map(fn (int $index): TestWorker => $this->numbered($pass, $configuration, $index), range(0, $this->slots() - 1));
    }

    // Because the serial pass starts only after every worker has stopped, the ports of the first worker are free again.
    public function serial(string $pass, string $configuration): TestWorker
    {
        return new TestWorker($pass.' serial', $this->scratch.'/'.$pass.'/serial', $this->ports->block(0), $configuration, '--group='.self::SERIAL_GROUP);
    }

    // Because each worker runs one process at a time, its block of ports bounds how many run at once.
    public function slots(): int
    {
        return $this->ports->blocks();
    }

    /**
     * @return list<string>
     */
    public function junitReports(): array
    {
        $reports = glob($this->scratch.'/*/*/junit*.xml');

        return false === $reports ? [] : $reports;
    }
}
