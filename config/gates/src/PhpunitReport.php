<?php

declare(strict_types=1);

namespace Intakt\Gates;

final class PhpunitReport
{
    /**
     * @return list<string>
     */
    public static function findings(string $run, ProcessResult $result): array
    {
        if ($result->succeeded()) {
            return [];
        }

        preg_match_all('/^\d+\) (.+)\R((?:.+(?:\R|\z))*)/m', $result->output, $entries, PREG_SET_ORDER);

        if ([] === $entries) {
            return [$run.': phpunit exited with code '.$result->exitCode.': '.self::reason($result)];
        }

        return array_map(static fn (array $entry): string => self::finding($run, $entry[1], trim($entry[2])), $entries);
    }

    /**
     * @return list<string>
     */
    public static function listingDiagnostics(string $run, string $eventsLog): array
    {
        $events = is_readable($eventsLog) ? file_get_contents($eventsLog) : false;

        if (false === $events) {
            return [$run.': phpunit listed its test files without writing its events log'];
        }

        // Because PHPUnit leaves a test file it cannot load out of the list without a word, only its event log tells, and the workers would never run it.
        preg_match_all('/^Test Runner Triggered (?!Garbage Collection)(.+)$/m', $events, $triggered);

        return array_map(static fn (string $event): string => $run.': '.$event, $triggered[1]);
    }

    private static function reason(ProcessResult $result): string
    {
        if ('' !== trim($result->errors)) {
            return $result->firstErrorLine();
        }

        $lines = preg_split('/\R/', trim($result->output));

        return false === $lines ? '' : (string) end($lines);
    }

    private static function finding(string $run, string $test, string $message): string
    {
        return '' === $message ? $run.': '.$test : $run.': '.$test.': '.$message;
    }
}
