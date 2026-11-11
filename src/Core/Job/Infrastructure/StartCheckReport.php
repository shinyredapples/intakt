<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use SplFileInfo;

// Reads what docker/job/start-check.sh left in the output directory; the job ran after it and could rewrite it, so anything malformed is a failure, never a pass.
final readonly class StartCheckReport
{
    public const string FILE = '.intakt-start-check';

    // At most this many failures reach the ledger, so a job cannot flood it through its own report.
    public const int MAX_FAILURES = 20;

    private const int MAX_BYTES = 64 * 1024;
    private const int MAX_REASON_BYTES = 255;
    private const string END = 'end';

    /**
     * @return list<StartCheckFailure>
     */
    public function failuresIn(string $outputDirectory): array
    {
        $report = new SplFileInfo($outputDirectory.'/'.self::FILE);

        if (!$report->isLink() && !file_exists($report->getPathname())) {
            return [new StartCheckFailure(StartCheck::Output, 'no report in the output directory: it could not be written, or the check did not run')];
        }

        if ($report->isLink() || !$report->isFile()) {
            return [new StartCheckFailure(StartCheck::Report, 'the report is not a plain file')];
        }

        if ($report->getSize() > self::MAX_BYTES) {
            return [new StartCheckFailure(StartCheck::Report, sprintf('the report is larger than %d bytes', self::MAX_BYTES))];
        }

        $contents = file_get_contents($report->getPathname());

        return false === $contents
            ? [new StartCheckFailure(StartCheck::Report, 'the report cannot be read')]
            : array_slice($this->failuresListedIn($contents), 0, self::MAX_FAILURES);
    }

    /**
     * @return list<StartCheckFailure>
     */
    private function failuresListedIn(string $contents): array
    {
        $lines = explode("\n", $contents);

        if ([self::END, ''] !== array_slice($lines, -2)) {
            return [new StartCheckFailure(StartCheck::Report, 'the report does not end with its end line')];
        }

        $listed = array_slice($lines, 0, -2);

        return array_map($this->failureOn(...), $listed, array_keys($listed));
    }

    // Printable ASCII only, so a reason can always be stored as JSON.
    private function failureOn(string $line, int $index): StartCheckFailure
    {
        $checks = implode('|', array_map(static fn (StartCheck $check): string => $check->value, StartCheck::cases()));

        if (1 !== preg_match('/^('.$checks.'): ([\x20-\x7E]+)$/D', $line, $match)) {
            return new StartCheckFailure(StartCheck::Report, sprintf('line %d of the report cannot be read', $index + 1));
        }

        return new StartCheckFailure(StartCheck::from($match[1]), substr($match[2], 0, self::MAX_REASON_BYTES));
    }
}
