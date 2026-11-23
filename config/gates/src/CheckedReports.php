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
    private const string STATUS_LINE = '/^ {0,3}(?:(?:[-*+]|\d{1,9}[.)])\s+)?\*\*(?:Decision|Status)(?::\*\*|\*\*:)\s*(?<value>.*)$/i';

    // Any indentation or blockquote, to report a ready status the strict format above would not read.
    private const string ANY_STATUS_LINE = '/^[\s>]*(?:(?:[-*+]|\d{1,9}[.)])[\s>]+)?\*\*(?:Decision|Status)(?::\*\*|\*\*:)\s*(?<value>.*)$/i';

    // Read as the start of the value, so `ready to file as a new issue` counts and `recheck needed before filing` does not.
    private const string READY = '/^[`*_\s]*ready to file\b/i';

    private const string CHECKED_LINE = '/^- \*\*Checked:\*\*\s*(?<rest>.*)$/m';

    private const string LEADING_DATE = '/^(?<date>\d{4}-\d{2}-\d{2})\b/';

    private const string PULL_REQUESTS_LINE = '/^- \*\*Pull requests and commits:\*\*[ \t]*(?<rest>\S.*)$/m';

    // A pull request number, a URL with a host name ("https://" alone names nothing) or a commit hash with at least one letter and one digit, because a line that names none of them ("none", "-") says what was found only through the date of the search, and a word of hex letters alone ("defaced") or a number alone names no commit.
    private const string REFERENCE = '~#\d+\b|\bhttps?://(?:[\w-]+\.)+[A-Za-z]{2,}|(?<![\w-])(?=[0-9a-f]*[a-f])(?=[0-9a-f]*\d)[0-9a-f]{7,40}(?![\w-])~';

    // Read as the start of the text, like READY, because a line that found nothing carries the date of the search even when it names what was searched; no \b, which "_none found_" would defeat.
    private const string NONE = '/^[`*_\s-]*(?:none|nothing|no)(?![\p{L}\p{N}])/iu';

    private const string ANY_DATE = '/\b(?<date>\d{4}-\d{2}-\d{2})\b/';

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
                $findings[] = 'upstream/'.$folder.'/REPORT.md '.$finding;
            }
        }

        return $findings;
    }

    // One strict format, failing closed on anything else, because each unusual Markdown shape could hide an unchecked report.
    private function findingOf(string $report): ?string
    {
        $contents = is_file($report) ? file_get_contents($report) : false;

        if (false === $contents) {
            return null;
        }

        $lines = $this->codeBlocks->blanked(explode("\n", $contents));
        $headings = count(array_filter($lines, $this->isStatusHeading(...)));
        $readyLines = array_filter($this->statusValues($lines, self::ANY_STATUS_LINE), $this->isReady(...));

        if ($headings > 1) {
            return 'has more than one Upstream status section';
        }

        if (0 === $headings) {
            return [] === $readyLines
                ? null
                : 'is ready to file but has no "'.self::STATUS_HEADING.'" section: the gate reads the status and the "Checked" line only under that heading, written exactly';
        }

        return $this->sectionFinding($this->statusSection($lines), $readyLines);
    }

    private function isStatusHeading(string $line): bool
    {
        return 1 === preg_match(self::SECTION_HEADING, $line) && self::STATUS_HEADING === trim($line);
    }

    // Keyed by line number, so the top-level lines can be told apart from the other ready lines; the last one decides.
    /**
     * @param array<int, string> $lines
     *
     * @return array<int, string>
     */
    private function statusValues(array $lines, string $pattern): array
    {
        $values = [];

        foreach ($lines as $number => $line) {
            if (1 === preg_match($pattern, $line, $match)) {
                $values[$number] = $match['value'];
            }
        }

        return $values;
    }

    private function isReady(string $value): bool
    {
        return 1 === preg_match(self::READY, $value);
    }

    // Only this section, because a Status line in another section (a fix experiment) would otherwise retire a ready report.
    /**
     * @param list<string> $lines
     *
     * @return array<int, string>
     */
    private function statusSection(array $lines): array
    {
        $section = [];
        $inside = false;

        foreach ($lines as $number => $line) {
            if (1 === preg_match(self::SECTION_HEADING, $line)) {
                $inside = $this->isStatusHeading($line);

                continue;
            }

            if ($inside) {
                $section[$number] = $line;
            }
        }

        return $section;
    }

    /**
     * @param array<int, string> $section
     * @param array<int, string> $readyLines
     */
    private function sectionFinding(array $section, array $readyLines): ?string
    {
        $statuses = $this->statusValues($section, self::STATUS_LINE);

        if ([] !== array_diff_key($readyLines, $statuses)) {
            return 'has a ready to file status outside the top-level lines of its Upstream status section: write the status as a list item in the Upstream status section';
        }

        $text = implode("\n", $section);
        $finding = $this->isReady(array_last($statuses) ?? '') ? $this->checkFinding($text) ?? $this->pullRequestsFinding($text) : null;

        return null === $finding ? null : 'is ready to file '.$finding;
    }

    private function checkFinding(string $report): ?string
    {
        if (1 !== preg_match(self::CHECKED_LINE, $report, $match)) {
            return 'but has no "Checked" line (date, upstream commit or tag, duplicate search, preferred form and target branch)';
        }

        if (1 !== preg_match(self::LEADING_DATE, $match['rest'], $match)) {
            return 'but its "Checked" line does not start with a date as YYYY-MM-DD';
        }

        $date = $this->dateOf($match['date']);

        if (!$date instanceof DateTimeImmutable) {
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

    // Upstream often fixes a bug in a pull request with no issue, or only in a commit, so a ready report names what that search found.
    private function pullRequestsFinding(string $report): ?string
    {
        if (1 !== preg_match(self::PULL_REQUESTS_LINE, $report, $match)) {
            return 'but has no "Pull requests and commits" line (the pull requests and commits the check found, or "none found" with the date of the check)';
        }

        if (1 !== preg_match(self::NONE, $match['rest']) && 1 === preg_match(self::REFERENCE, $match['rest'])) {
            return null;
        }

        preg_match_all(self::ANY_DATE, $match['rest'], $dates);

        return array_any($dates['date'], fn (string $date): bool => $this->dateOf($date) instanceof DateTimeImmutable)
            ? null
            : 'but its "Pull requests and commits" line names no pull request (#n), URL or commit hash and no date of the check as YYYY-MM-DD';
    }

    // The one home of the date rule, so an impossible day such as 2026-02-30 fails on every line that needs a date.
    private function dateOf(string $date): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        return false !== $parsed && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }
}
