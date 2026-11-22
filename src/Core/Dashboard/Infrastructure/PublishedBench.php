<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\BenchHeadline;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Read from the review bench table of docs/bench.md, the one place the published run is kept, so the tile changes with the next published run and never carries a copied figure.
final readonly class PublishedBench
{
    private const string SECTION_HEADING = '/^## Review bench[ \t]*$/';
    // Only the unit the bench measures in, so a column of another unit, such as per change, is never shown as a total.
    private const string FOUND_COLUMN = '/^Defects found(?: \(measured\))?$/';
    private const string FALSE_ALARMS_COLUMN = '/^False alarms(?: \(measured\))?$/';
    private const string PANEL_CELL = '/^Panel\b/';
    private const string FOUND_CELL = '/^(\d+) of (\d+)$/';
    private const string COUNT_CELL = '/^\d+$/';
    private const string RUN_DATE = '/^Date of the run: ((\d{4})-(\d{2})-(\d{2}))\./';
    // Every occurrence is counted, whatever its digits, so two sentences make the count ambiguous.
    private const string REPEAT = '/\bEach change ran (\d+) times\./';

    public function __construct(
        #[Autowire('%kernel.project_dir%/docs/bench.md')]
        private string $readme,
    ) {
    }

    // Null when the bench page or its table is missing or changed shape, so the page shows no bench tile rather than a wrong one.
    public function headline(): ?BenchHeadline
    {
        $section = MarkdownSection::named($this->text(), self::SECTION_HEADING);
        $table = null === $section ? null : MarkdownTable::only($section);
        if (null === $section || null === $table || !$this->namesTheColumns($table[0])) {
            return null;
        }

        $panel = $this->panelResult(array_slice($table, 2));
        $ranOn = $this->runDate($section);
        if (null === $panel || null === $ranOn) {
            return null;
        }

        return new BenchHeadline($panel[0], $panel[1], $panel[2], $ranOn, $this->runs($section));
    }

    private function text(): string
    {
        return is_file($this->readme) && is_readable($this->readme) ? (string) file_get_contents($this->readme) : '';
    }

    /**
     * @param list<string> $header
     */
    private function namesTheColumns(array $header): bool
    {
        return 1 === preg_match(self::FOUND_COLUMN, $header[1] ?? '')
            && 1 === preg_match(self::FALSE_ALARMS_COLUMN, $header[2] ?? '');
    }

    /**
     * @param list<list<string>> $body
     *
     * @return array{int, int, int}|null
     */
    private function panelResult(array $body): ?array
    {
        $panels = array_values(array_filter($body, static fn (array $row): bool => 1 === preg_match(self::PANEL_CELL, $row[0] ?? '')));
        if (1 !== count($panels)
            || 1 !== preg_match(self::FOUND_CELL, $panels[0][1] ?? '', $found)
            || 1 !== preg_match(self::COUNT_CELL, $panels[0][2] ?? '')) {
            return null;
        }

        [$defects, $planted, $falseAlarms] = [$this->countOf($found[1]), $this->countOf($found[2]), $this->countOf($panels[0][2])];
        if (in_array(null, [$defects, $planted, $falseAlarms], true) || 0 === $planted || $defects > $planted) {
            return null;
        }

        return [$defects, $planted, $falseAlarms];
    }

    /**
     * @param list<string> $section
     */
    private function runDate(array $section): ?string
    {
        $dates = array_values(array_filter(array_map(
            static fn (string $line): ?array => 1 === preg_match(self::RUN_DATE, $line, $date) ? $date : null,
            $section,
        )));
        if (1 !== count($dates)) {
            return null;
        }

        [, $date, $year, $month, $day] = $dates[0];

        return checkdate((int) $month, (int) $day, (int) $year) ? $date : null;
    }

    /**
     * @param list<string> $section
     */
    private function runs(array $section): ?int
    {
        $repeats = array_merge(...array_map(
            static fn (string $line): array => false === preg_match_all(self::REPEAT, $line, $matches) ? [] : $matches[1],
            $section,
        ));

        $runs = 1 === count($repeats) ? $this->countOf($repeats[0]) : null;

        return 0 === $runs ? null : $runs;
    }

    // A count beyond the integer range or zero-padded (02) is refused rather than read, so the tile never shows a figure the bench page does not hold as written.
    private function countOf(string $digits): ?int
    {
        $count = filter_var($digits, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return is_int($count) ? $count : null;
    }
}
