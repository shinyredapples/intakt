<?php

declare(strict_types=1);

namespace Intakt\Gates;

use DOMDocument;
use DOMElement;
use DOMXPath;

final readonly class TestDurations
{
    private const int SLOWEST = 10;

    public function __construct(private string $root)
    {
    }

    /**
     * @param list<string> $reports
     *
     * @return array<string, float>
     */
    public function read(array $reports): array
    {
        // Because a missing or unreadable report adds nothing: the PHPUnit exit status alone decides the gate.
        $seconds = [];

        foreach ($reports as $report) {
            foreach ($this->testCases($report) as $case) {
                $file = $this->relative($case->getAttribute('file'));
                $seconds[$file] = ($seconds[$file] ?? 0.0) + (float) $case->getAttribute('time');
            }
        }

        arsort($seconds);

        return $seconds;
    }

    /**
     * @param array<string, float> $durations
     */
    public function write(array $durations, string $cacheDirectory): void
    {
        $directory = $cacheDirectory.'/tests';

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            return;
        }

        file_put_contents($directory.'/durations.json', json_encode($durations, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n", LOCK_EX);
    }

    /**
     * @param array<string, float> $durations
     *
     * @return array<string, float>
     */
    public function slowest(array $durations): array
    {
        return array_slice($durations, 0, self::SLOWEST, true);
    }

    /**
     * @return list<DOMElement>
     */
    private function testCases(string $report): array
    {
        if (!is_file($report)) {
            return [];
        }

        $document = new DOMDocument();

        if (!@$document->load($report, LIBXML_NONET)) {
            return [];
        }

        $cases = [];

        $found = new DOMXPath($document)->query('//testcase[@file]');

        foreach (false === $found ? [] : $found as $case) {
            if ($case instanceof DOMElement) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    private function relative(string $file): string
    {
        $prefix = rtrim($this->root, '/').'/';

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
    }
}
