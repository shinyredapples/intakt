<?php

declare(strict_types=1);

namespace Intakt\Gates;

use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Clock\ClockInterface;

final readonly class CheckedReports
{
    private const int MAX_CHECK_AGE_DAYS = 14;

    private const string STATUS_HEADING = '## Upstream status';

    private const string SECTION_HEADING = '/^#{1,2}\s/';

    private const string READY_STATUS = '/^- \*\*(?:Decision|Status):\*\*\s*`(?<value>[^`]*)`/';

    private const string CHECKED_LINE = '/^- \*\*Checked:\*\*\s*(?<date>\S+)/m';

    public function __construct(
        private FencedCodeBlocks $codeBlocks,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $folders
     *
     * @return list<string>
     */
    public function findings(Workspace $workspace, array $folders): array
    {
        $findings = [];

        foreach ($folders as $folder) {
            $finding = $this->findingOf($workspace->root.'/upstream/'.$folder.'/REPORT.md');

            if (null !== $finding) {
                $findings[] = 'upstream/'.$folder.'/REPORT.md is ready to file '.$finding;
            }
        }

        return $findings;
    }

    private function findingOf(string $report): ?string
    {
        $contents = is_file($report) ? file_get_contents($report) : false;

        if (false === $contents) {
            return null;
        }

        $lines = $this->statusSection($this->codeBlocks->blanked(explode("\n", $contents)));

        return $this->readyToFile($lines) ? $this->checkFinding(implode("\n", $lines)) : null;
    }

    // Other sections (a fix experiment, notes) may carry their own Status lines, which say nothing about filing.
    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function statusSection(array $lines): array
    {
        $section = [];
        $inside = false;

        foreach ($lines as $line) {
            if (1 === preg_match(self::SECTION_HEADING, $line)) {
                $inside = self::STATUS_HEADING === rtrim($line);

                continue;
            }

            if ($inside) {
                $section[] = $line;
            }
        }

        return $section;
    }

    // The last Decision or Status line decides, because a later `not filed` line retires an earlier `ready to file`.
    /**
     * @param list<string> $lines
     */
    private function readyToFile(array $lines): bool
    {
        $current = null;

        foreach ($lines as $line) {
            if (1 === preg_match(self::READY_STATUS, $line, $match)) {
                $current = $match['value'];
            }
        }

        return 'ready to file' === $current;
    }

    private function checkFinding(string $report): ?string
    {
        if (1 !== preg_match(self::CHECKED_LINE, $report, $match)) {
            return 'but has no "Checked" line (date, upstream commit or tag, duplicate search, preferred form and target branch)';
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $match['date'], new DateTimeZone('UTC'));

        if (false === $date || $date->format('Y-m-d') !== $match['date']) {
            return 'but its "Checked" line does not start with a date as YYYY-MM-DD';
        }

        $age = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0)->diff($date);

        if ($age->invert < 1 && $age->days > 0) {
            return 'but its "Checked" line is dated in the future: '.$match['date'];
        }

        return $age->invert > 0 && $age->days > self::MAX_CHECK_AGE_DAYS
            ? 'but its "Checked" line is older than '.self::MAX_CHECK_AGE_DAYS.' days ('.$match['date'].'): check it against upstream again before posting'
            : null;
    }
}
