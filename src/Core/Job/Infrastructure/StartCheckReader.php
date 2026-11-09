<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\EgressPolicy;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Job\Contract\StartCheckItem;
use Intakt\Core\Job\Contract\StartCheckReport;

// The report is written by the job into a directory it controls, so it is untrusted: an item the job did not report counts as failed, never as passed.
final readonly class StartCheckReader
{
    public const string FILE = '.start-check';

    private const int MAX_BYTES = 65536;
    private const int MAX_REASON = 200;

    public function read(JobSpec $spec): StartCheckReport
    {
        $reported = $this->reported($spec->outputDir.'/'.self::FILE);
        $items = [];

        foreach ($this->expected($spec) as $name) {
            $items[] = $reported[$name] ?? new StartCheckItem($name, false, 'the start check did not report it');
        }

        return new StartCheckReport($items);
    }

    /**
     * @return list<string>
     */
    private function expected(JobSpec $spec): array
    {
        $names = ['output', ...array_map(static fn (JobInput $input): string => 'input:'.$input->containerPath, $spec->inputs)];

        return EgressPolicy::None === $spec->egress ? $names : [...$names, 'vendor'];
    }

    /**
     * @return array<string, StartCheckItem>
     */
    private function reported(string $file): array
    {
        // A link is refused because it could point the read at a host file through the job's own directory.
        if (is_link($file) || !is_file($file) || filesize($file) > self::MAX_BYTES) {
            return [];
        }

        $content = file_get_contents($file);
        $items = [];

        foreach (explode("\n", false === $content ? '' : $content) as $line) {
            $fields = explode("\t", $line);

            if (3 === count($fields) && in_array($fields[1], ['ok', 'fail'], true)) {
                $items[$fields[0]] = new StartCheckItem($fields[0], 'ok' === $fields[1], $this->printable($fields[2]));
            }
        }

        return $items;
    }

    private function printable(string $reason): string
    {
        return substr(preg_replace('/[^\x20-\x7e]/', '?', $reason) ?? '', 0, self::MAX_REASON);
    }
}
