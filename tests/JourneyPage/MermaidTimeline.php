<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

// A line this reader does not know fails the run, so the drawn timeline never silently drops what github.com shows.
final readonly class MermaidTimeline
{
    public const string BLOCK = '/^```mermaid\n[ \t]*timeline[ \t]*\n(.*?)^```[ \t]*$/ms';
    private const string SEPARATOR = '/\s+:\s+/';

    public function in(string $page): Timeline
    {
        if (1 !== preg_match_all(self::BLOCK, $page, $blocks)) {
            throw new JourneyInputFailed('the page needs exactly one mermaid timeline block');
        }

        $title = '';
        /** @var list<array{name: string, periods: list<array{when: string, events: list<string>}>}> $sections */
        $sections = [];

        foreach (explode("\n", $blocks[1][0]) as $index => $line) {
            $line = trim($line);

            if ('' === $line) {
                continue;
            }

            if (str_starts_with($line, 'title ')) {
                $title = trim(substr($line, 6));

                continue;
            }

            if (str_starts_with($line, 'section ')) {
                $sections[] = ['name' => trim(substr($line, 8)), 'periods' => []];

                continue;
            }

            $sections = $this->withPeriodLine($sections, $index, $line);
        }

        return new Timeline($title, array_map(static fn (array $section): TimelineSection => new TimelineSection($section['name'], array_map(static fn (array $period): TimelinePeriod => new TimelinePeriod($period['when'], $period['events']), $section['periods'])), $sections));
    }

    /**
     * @param list<array{name: string, periods: list<array{when: string, events: list<string>}>}> $sections
     *
     * @return list<array{name: string, periods: list<array{when: string, events: list<string>}>}>
     */
    private function withPeriodLine(array $sections, int $index, string $line): array
    {
        $section = array_key_last($sections);

        if (null === $section) {
            throw $this->refused($index, 'it comes before the first section');
        }

        if (str_starts_with($line, ':')) {
            $period = array_key_last($sections[$section]['periods']) ?? throw $this->refused($index, 'it continues a period that does not exist');
            $sections[$section]['periods'][$period]['events'] = [...$sections[$section]['periods'][$period]['events'], ...$this->events(substr($line, 1))];

            return $sections;
        }

        $parts = preg_split(self::SEPARATOR, $line, 2);

        if (!is_array($parts) || 2 !== count($parts)) {
            throw $this->refused($index, 'it is not a title, a section or a period with its events');
        }

        $sections[$section]['periods'][] = ['when' => $parts[0], 'events' => $this->events($parts[1])];

        return $sections;
    }

    private function refused(int $index, string $reason): JourneyInputFailed
    {
        return new JourneyInputFailed('timeline line '.($index + 1).' is refused: '.$reason);
    }

    /**
     * @return list<string>
     */
    private function events(string $text): array
    {
        $events = preg_split(self::SEPARATOR, ' '.trim($text).' ');

        return array_values(array_filter(array_map(trim(...), false === $events ? [] : $events), static fn (string $event): bool => '' !== $event));
    }
}
