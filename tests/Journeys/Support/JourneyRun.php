<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

final readonly class JourneyRun
{
    private const string GATE = 'journeys';
    private const string FINDING = '/^(?<file>[^:]+): journey "(?<journey>.+?)" failed at step "(?<step>.+?)": (?<reason>.*)$/s';

    /**
     * @param list<JourneyFailure> $failures
     */
    private function __construct(
        public array $failures,
        public string $output,
        public bool $timedOut,
    ) {
    }

    // A run stopped at the job's limit may have printed no result line, so its output is not parsed.
    public static function timedOut(string $output): self
    {
        return new self([], $output, timedOut: true);
    }

    public static function fromOutput(string $output): self
    {
        $lines = self::jsonLines($output);
        $failures = self::failuresIn($lines);

        if ([] === $failures && !self::passedIn($lines)) {
            throw new UnreadableJourneyRun('the output holds no failing journey and no pass, so the run is not known to be green');
        }

        return new self($failures, $output, timedOut: false);
    }

    public function isRed(): bool
    {
        return $this->timedOut || [] !== $this->failures;
    }

    public function tail(int $lines): string
    {
        $all = explode("\n", rtrim($this->output, "\n"));

        return implode("\n", array_slice($all, -$lines));
    }

    /**
     * @return list<array<mixed>>
     */
    private static function jsonLines(string $output): array
    {
        $lines = [];

        foreach (explode("\n", $output) as $line) {
            $decoded = json_validate($line) ? json_decode($line, true) : null;

            if (is_array($decoded)) {
                $lines[] = $decoded;
            }
        }

        return $lines;
    }

    /**
     * @param list<array<mixed>> $lines
     *
     * @return list<JourneyFailure>
     */
    private static function failuresIn(array $lines): array
    {
        $gateLines = array_filter($lines, static fn (array $line): bool => self::GATE === ($line['gate'] ?? null));

        return array_merge([], ...array_map(self::failuresOfGateLine(...), array_values($gateLines)));
    }

    /**
     * @param array<mixed> $line
     *
     * @return list<JourneyFailure>
     */
    private static function failuresOfGateLine(array $line): array
    {
        $failures = self::failuresAmong($line['findings'] ?? null);

        if ([] === $failures && false === ($line['ok'] ?? null)) {
            throw new UnreadableJourneyRun('the journeys gate failed without a finding that names a journey and its step, so the run is not known to be green');
        }

        return $failures;
    }

    /**
     * @return list<JourneyFailure>
     */
    private static function failuresAmong(mixed $findings): array
    {
        $failures = [];

        foreach (is_array($findings) ? $findings : [] as $finding) {
            if (is_string($finding) && 1 === preg_match(self::FINDING, $finding, $match)) {
                $failures[] = new JourneyFailure($match['file'], $match['journey'], $match['step'], $match['reason']);
            }
        }

        return $failures;
    }

    /**
     * @param list<array<mixed>> $lines
     */
    private static function passedIn(array $lines): bool
    {
        return array_any($lines, static fn (array $line): bool => 'pass' === ($line['result'] ?? null));
    }
}
