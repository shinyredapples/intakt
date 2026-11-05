<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class TestWorkers
{
    private const string SERIAL_GROUP = 'serial';

    public function __construct(public string $scratch, private TestPorts $ports)
    {
    }

    public function numbered(string $pass, string $configuration, int $index): TestWorker
    {
        return new TestWorker($pass, $this->scratch.'/'.$pass.'/worker-'.$index, $this->ports->block($index), $configuration, '--exclude-group='.self::SERIAL_GROUP);
    }

    // Because the serial pass starts only after every worker has stopped, the ports of the first worker are free again.
    public function serial(string $pass, string $configuration): TestWorker
    {
        return new TestWorker($pass.' serial', $this->scratch.'/'.$pass.'/serial', $this->ports->block(0), $configuration, '--group='.self::SERIAL_GROUP);
    }

    /**
     * @return list<string>
     */
    public function junitReports(): array
    {
        $reports = glob($this->scratch.'/*/*/junit.xml');

        return false === $reports ? [] : $reports;
    }
}
