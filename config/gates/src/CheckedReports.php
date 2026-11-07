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

    // Allows up to three spaces because Markdown still renders such a line as a heading, so it must bound the section too.
    private const string SECTION_HEADING = '/^ {0,3}#{1,2}\s/';

    // Any list marker or none, and either colon placement, because a status written otherwise would hide an unchecked report.
    private const string STATUS_LINE = '/^\s*(?:(?:[-*+]|\d{1,9}[.)])\s+)?\*\*(?:Decision|Status)(?::\*\*|\*\*:)\s*(?<value>.*)$/i';

    // Read as the start of the value, so `ready to file as a new issue` counts and `recheck needed before filing` does not.
    private const string READY = '/^[`*_\s]*ready to file\b/i';

    private const string CHECKED_LINE = '/^- \*\*Checked:\*\*\s*(?<rest>.*)$/m';

    private const string LEADING_DATE = '/^(?<date>\d{4}-\d{2}-\d{2})\b/';

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

        $lines = $this->codeBlocks->blanked(explode("\n", $contents));
        $section = $this->statusSection($lines);

        // Without the heading the rule fails closed, because a heading written otherwise would hide an unchecked report.
        if (null === $section) {
            return in_array(true, array_map($this->isReady(...), $this->statusValues($lines)), true)
                ? 'but has no "'.self::STATUS_HEADING.'" section: the gate reads the status and the "Checked" line only under that heading, written exactly'
                : null;
        }

        return $this->isReady(array_last($this->statusValues($section)) ?? '')
            ? $this->checkFinding(implode("\n", $section))
            : null;
    }

    // Only this section, because a Status line in another section (a fix experiment) would otherwise retire a ready report.
    /**
     * @param list<string> $lines
     *
     * @return list<string>|null
     */
    private function statusSection(array $lines): ?array
    {
        $section = null;
        $inside = false;

        foreach ($lines as $line) {
            if (1 === preg_match(self::SECTION_HEADING, $line)) {
                $inside = self::STATUS_HEADING === trim($line);
                $section ??= $inside ? [] : null;

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
     *
     * @return list<string>
     */
    private function statusValues(array $lines): array
    {
        $values = [];

        foreach ($lines as $line) {
            if (1 === preg_match(self::STATUS_LINE, $line, $match)) {
                $values[] = $match['value'];
            }
        }

        return $values;
    }

    private function isReady(string $value): bool
    {
        return 1 === preg_match(self::READY, $value);
    }

    private function checkFinding(string $report): ?string
    {
        if (1 !== preg_match(self::CHECKED_LINE, $report, $match)) {
            return 'but has no "Checked" line (date, upstream commit or tag, duplicate search, preferred form and target branch)';
        }

        if (1 !== preg_match(self::LEADING_DATE, $match['rest'], $match)) {
            return 'but its "Checked" line does not start with a date as YYYY-MM-DD';
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
