<?php

declare(strict_types=1);

namespace Intakt\Gates;

use DOMDocument;
use DOMElement;
use DOMXPath;
use JsonException;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final readonly class TestDurations
{
    private const int SLOWEST = 10;
    private const string FILE = '/tests/durations.json';

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
     * @return array<string, float>
     */
    public function recorded(string $cacheDirectory): array
    {
        $file = $cacheDirectory.self::FILE;

        if (!is_file($file)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($file), true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Because an unreadable record only costs the order of the queue, never the result of the run.
            return [];
        }

        $seconds = [];

        foreach (is_array($decoded) ? $decoded : [] as $testFile => $duration) {
            if (is_string($testFile) && (is_float($duration) || is_int($duration))) {
                $seconds[$testFile] = (float) $duration;
            }
        }

        return $seconds;
    }

    /**
     * @param list<string>         $files    relative to the root
     * @param array<string, float> $recorded
     *
     * @return list<string>
     */
    public function longestFirst(array $files, array $recorded): array
    {
        $unrecorded = array_values(array_filter($files, static fn (string $file): bool => !isset($recorded[$file])));
        $timed = array_values(array_diff($files, $unrecorded));

        // Because a file without a recorded duration may be the longest of all, it starts first, the largest of them first.
        usort($unrecorded, fn (string $first, string $second): int => $this->size($second) <=> $this->size($first));
        usort($timed, static fn (string $first, string $second): int => $recorded[$second] <=> $recorded[$first]);

        return [...$unrecorded, ...$timed];
    }

    /**
     * @param array<string, float> $durations
     */
    public function write(array $durations, string $cacheDirectory): void
    {
        $json = json_encode($durations, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";

        try {
            new Filesystem()->dumpFile($cacheDirectory.self::FILE, $json);
        } catch (IOException) {
            // Because the durations never affect the run, and dumpFile() turns every PHP warning into this exception, so none reaches the gate lines.
            return;
        }
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

    private function size(string $file): int
    {
        $path = $this->root.'/'.$file;

        return is_file($path) ? (int) filesize($path) : 0;
    }

    private function relative(string $file): string
    {
        $prefix = rtrim($this->root, '/').'/';

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
    }
}
