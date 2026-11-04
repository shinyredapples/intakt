<?php

declare(strict_types=1);

namespace Intakt\Gates;

final class JourneysGate implements Gate
{
    private const string DIRECTORY = 'tests/Journeys';
    // Above bin/instance's default of a minute, because a journey walks several pages and commands.
    private const string JOURNEY_SECONDS = '240';
    private const string FAILURE_LINE = '/journey ".+" failed at step ".+"/';

    public function name(): string
    {
        return 'journeys';
    }

    public function run(Workspace $workspace): GateResult
    {
        if (!$workspace->hasTool('phpunit')) {
            return GateResult::failedBecause($this->name(), 'missing tool phpunit');
        }

        $files = glob($workspace->root.'/'.self::DIRECTORY.'/*JourneyTest.php');

        if (false === $files || [] === $files) {
            return GateResult::failedBecause($this->name(), 'no journey found in '.self::DIRECTORY);
        }

        sort($files);

        return GateResult::withFindings($this->name(), $this->findings($workspace, $files));
    }

    /**
     * @param list<string> $files
     *
     * @return list<string>
     */
    private function findings(Workspace $workspace, array $files): array
    {
        $findings = [];

        foreach ($files as $file) {
            $result = $this->walk($workspace, $file);

            if (!$result->succeeded()) {
                $findings[] = basename($file).': '.$this->reason($result);
            }
        }

        return $findings;
    }

    // One instance per journey, so a journey that breaks its database cannot make the next one fail.
    private function walk(Workspace $workspace, string $file): ProcessResult
    {
        // The status line goes to descriptor 3, which the shell points at the output so a failed boot is read too.
        $command = [
            'sh', '-c', 'exec "$@" 3>&1', 'sh',
            PHP_BINARY, $workspace->instance(), '--',
            PHP_BINARY, $workspace->tool('phpunit'),
            '--no-configuration', '--bootstrap='.$workspace->autoload(), '--group=journeys', '--colors=never', '--no-progress',
            '--fail-on-empty-test-suite', '--fail-on-skipped', '--fail-on-incomplete', '--fail-on-risky', '--fail-on-warning',
            '--fail-on-notice', '--fail-on-deprecation', '--do-not-cache-result',
            $file,
        ];

        return $workspace->command($command, ['INTAKT_INSTANCE_COMMAND_SECONDS' => self::JOURNEY_SECONDS]);
    }

    private function reason(ProcessResult $result): string
    {
        $text = $result->output."\n".$result->errors;

        if (1 === preg_match(self::FAILURE_LINE, $text, $match)) {
            return $match[0];
        }

        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $text)), static fn (string $line): bool => '' !== $line));

        return [] === $lines ? 'exit '.$result->exitCode.' without output' : 'exit '.$result->exitCode.': '.array_last($lines);
    }
}
