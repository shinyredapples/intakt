<?php

declare(strict_types=1);

namespace Intakt\Gates;

use JsonException;

final readonly class ChildReport
{
    private const int SHOWN_OUTPUT_LINES = 9;

    private const int FAILED = 1;

    private function __construct(
        public string $line,
        private int $exitCode,
    ) {
    }

    public static function of(string $gate, ProcessResult $child): self
    {
        $line = trim($child->output);
        $passing = self::verdictOf($gate, $line);

        // Because a child that reports one verdict and exits with the other is as untrustworthy as one that reports none.
        if (null === $passing || ($passing ? 0 : self::FAILED) !== $child->exitCode) {
            return self::failedChild($gate, $child);
        }

        return new self($line, $child->exitCode);
    }

    public function isPassing(): bool
    {
        return 0 === $this->exitCode;
    }

    private static function verdictOf(string $gate, string $line): ?bool
    {
        if (str_contains($line, "\n")) {
            return null;
        }

        try {
            $report = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($report) || $gate !== ($report['gate'] ?? null) || !is_array($report['findings'] ?? null) || !array_is_list($report['findings'])) {
            return null;
        }

        return is_bool($report['ok'] ?? null) ? $report['ok'] : null;
    }

    private static function failedChild(string $gate, ProcessResult $child): self
    {
        $output = preg_split('/\R/', trim($child->errors."\n".$child->output));
        $lines = array_slice(array_values(array_filter(false === $output ? [] : $output,static fn (string $line): bool => '' !== trim($line))), -self::SHOWN_OUTPUT_LINES);
        $reason = 'the child process of the gate ended with exit code '.$child->exitCode.' and no valid result line';

        return new self(GateResult::withFindings($gate, [$reason, ...$lines])->toJson(), self::FAILED);
    }
}
