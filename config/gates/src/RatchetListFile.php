<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class RatchetListFile
{
    private const string CLEANUP_ISSUE = '/^#\s*Cleanup issue:\s*#\d+\s*$/';

    public function __construct(
        public string $path,
        public string $contents,
    ) {
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if (!$this->namesCleanupIssue()) {
            $problems[] = $this->path.': no cleanup issue in its header, add a line "# Cleanup issue: #<number>" above the entries';
        }

        if (!$this->holdsOnlyIgnoredErrors()) {
            $problems[] = $this->path.': holds more than parameters.ignoreErrors, so it could change the analysis';
        }

        return $problems;
    }

    private function namesCleanupIssue(): bool
    {
        foreach ($this->lines() as $line) {
            if (!str_starts_with($line, '#')) {
                return false;
            }

            if (1 === preg_match(self::CLEANUP_ISSUE, $line)) {
                return true;
            }
        }

        return false;
    }

    // Because a list sits outside the protected paths, nothing in it but ignored errors may reach PHPStan: no other parameter, include or service.
    private function holdsOnlyIgnoredErrors(): bool
    {
        $entryIndent = null;

        foreach (preg_split('/\R/', $this->contents) ?: [] as $line) {
            $text = trim($line);
            $indent = strlen($line) - strlen(ltrim($line));

            if ('' === $text || str_starts_with($text, '#')) {
                continue;
            }

            $entryIndent ??= 0 === $indent ? null : $indent;

            if ($this->isOutsideIgnoredErrors($text, $indent, $entryIndent)) {
                return false;
            }
        }

        return true;
    }

    private function isOutsideIgnoredErrors(string $text, int $indent, ?int $parameterIndent): bool
    {
        if (0 === $indent) {
            return 'parameters:' !== $text;
        }

        return $indent < (int) $parameterIndent || ($indent === $parameterIndent && 'ignoreErrors:' !== $text);
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/\R/', $this->contents) ?: []), static fn (string $line): bool => '' !== $line));
    }
}
