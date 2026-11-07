<?php

declare(strict_types=1);

namespace Intakt\Gates;

final class ContainerLintGate implements RunsTheChangesCode
{
    // Because `when@` blocks make the container differ per environment, each one the kernel ships is linted.
    private const array ENVIRONMENTS = ['dev', 'test', 'prod'];

    // Because Symfony wraps its error blocks at the terminal width, a narrow one would split words inside the finding.
    private const string UNWRAPPED_COLUMNS = '1000';

    private const string ERROR_START = '/^(\s*\[ERROR\]|In \S+ line \d+:$)/';
    private const string EXCEPTION_SOURCE = '/^In \S+ line \d+:$/';
    private const string USAGE_START = 'lint:container ';

    public function name(): string
    {
        return 'container';
    }

    public function run(Workspace $workspace): GateResult
    {
        $console = $workspace->root.'/bin/console';

        if (!is_file($console)) {
            return GateResult::failedBecause($this->name(), 'bin/console not found, so the container cannot be linted');
        }

        $findings = [];

        foreach ($this->lintEachEnvironment($workspace, $console) as $environment => $result) {
            if (!$result->succeeded()) {
                $findings[] = $environment.': '.$this->messageOf($result);
            }
        }

        return GateResult::withFindings($this->name(), $findings);
    }

    /**
     * @return array<string, ProcessResult>
     */
    private function lintEachEnvironment(Workspace $workspace, string $console): array
    {
        // Because the caller's debug or verbosity setting would change what is linted or silence the reason.
        $environment = ['COLUMNS' => self::UNWRAPPED_COLUMNS, 'APP_DEBUG' => false, 'SHELL_VERBOSITY' => false];
        $commands = [];

        foreach (self::ENVIRONMENTS as $name) {
            $commands[$name] = [[PHP_BINARY, $console, 'lint:container', '--env='.$name, '--no-ansi', '--no-interaction'], $environment];
        }

        return $workspace->commandsConcurrently($commands);
    }

    private function messageOf(ProcessResult $result): string
    {
        $lines = preg_split('/\R/', $result->errors."\n".$result->output);
        $lines = false === $lines ? [] : $lines;
        $start = array_find_key($lines, static fn (string $line): bool => 1 === preg_match(self::ERROR_START, $line));

        if (null === $start) {
            return 'lint:container exited with '.$result->exitCode.': '.$result->firstErrorLine();
        }

        $message = [];

        foreach (array_slice($lines, $start) as $line) {
            if (str_starts_with($line, self::USAGE_START)) {
                break;
            }

            $message[] = 1 === preg_match(self::EXCEPTION_SOURCE, $line) ? '' : trim((string) preg_replace('/^\s*\[ERROR\]/', '', $line));
        }

        return trim(implode(' ', $message));
    }
}
