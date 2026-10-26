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

    private static function finding(string $run, string $test, string $message): string
    {
        return '' === $message ? $run.': '.$test : $run.': '.$test.': '.$message;
    }

    private static function reason(ProcessResult $result): string
    {
        if ('' !== trim($result->errors)) {
            return $result->firstErrorLine();
        }

        $lines = preg_split('/\R/', trim($result->output));

        return false === $lines ? '' : (string) end($lines);
    }
}
