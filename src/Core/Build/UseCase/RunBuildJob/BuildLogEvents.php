<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

// Typed reads, because the log is the builder's to write, so a field of another type must count as absent and never reach a calculation.
final readonly class BuildLogEvents
{
    private const array USAGE_COUNTS = ['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens'];

    private const string TEST_RUN = '~phpunit|bin/check|composer\s+(?:test|check)~';

    private const string FAILED_TEST = '~^\s*\d+\) (\S+::\S+)~m';

    private const array EDIT_TOOLS = ['Edit', 'Write', 'MultiEdit'];

    /**
     * @return array<mixed>|null
     */
    public static function decode(string $line): ?array
    {
        $event = '{' === ($line[0] ?? '') ? json_decode($line, true) : null;

        return is_array($event) ? $event : null;
    }

    /**
     * @param array<mixed> $from
     *
     * @return array<mixed>
     */
    public static function arrayAt(array $from, string $key): array
    {
        $value = $from[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<mixed> $from
     */
    public static function textAt(array $from, string $key): string
    {
        $value = $from[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    // Counted as the Anthropic form counts them, cache reads and writes apart from the input, so every figure of the usage is added.
    public static function tokensIn(mixed $usage): int
    {
        $tokens = 0;

        foreach (self::USAGE_COUNTS as $name) {
            $count = is_array($usage) ? ($usage[$name] ?? 0) : 0;
            $tokens = self::plus($tokens, is_int($count) ? $count : 0);
        }

        return $tokens;
    }

    // Held at the largest integer instead of turning into a float, because the builder can write counts that add up past it.
    public static function plus(int $tokens, int $more): int
    {
        return $more <= 0 ? $tokens : ($more > PHP_INT_MAX - $tokens ? PHP_INT_MAX : $tokens + $more);
    }

    public static function runsTests(string $tool, string $command): bool
    {
        return 'Bash' === $tool && 1 === preg_match(self::TEST_RUN, $command);
    }

    // A commit attempt only, because one that changes nothing fails; the commit counts once its result names the commit it made.
    public static function triesToCommit(string $tool, string $command): bool
    {
        return 'Bash' === $tool && 1 === preg_match('~\bgit\s+commit\b~', $command) && !str_contains($command, '--allow-empty');
    }

    // Read from the line git prints for a commit it made, such as "[main 1a2b3c4] subject", because each commit has its own hash where two commands can read the same.
    public static function commitIn(string $text): string
    {
        return 1 === preg_match('~^\[[^\]\n]+ ([0-9a-f]{7,64})\] ~m', $text, $found) ? $found[1] : '';
    }

    // The id of the shell a test run went on in, when its result says so; null when it did not go to the background, and empty when it did but names no id.
    public static function backgroundShellIn(string $text): ?string
    {
        return 1 === preg_match('~running in (?:the )?background(?: with ID: ([\w-]+))?~i', $text, $found) ? ($found[1] ?? '') : null;
    }

    public static function showsEnded(string $text): bool
    {
        return 1 === preg_match('~<status>(?:completed|failed|killed)</status>~', $text);
    }

    /**
     * @param array<mixed> $input
     */
    public static function shellLookedAt(array $input): string
    {
        foreach (['bash_id', 'shell_id', 'task_id'] as $key) {
            if ('' !== self::textAt($input, $key)) {
                return self::textAt($input, $key);
            }
        }

        return '';
    }

    /**
     * @param array<mixed> $input
     */
    public static function changeBy(string $tool, array $input): string
    {
        $before = self::withoutWhitespace(self::textAt($input, 'old_string'));
        // Read from the list a MultiEdit carries, because its changes are not in the fields of a single edit.
        $edits = self::arrayAt($input, 'edits');
        $after = self::withoutWhitespace(self::textAt($input, 'new_string').self::textAt($input, 'content').([] === $edits ? '' : json_encode($edits, JSON_THROW_ON_ERROR)));

        return !in_array($tool, self::EDIT_TOOLS, true) || $before === $after ? '' : 'edit:'.$before.'>'.$after;
    }

    public static function textOf(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        $texts = array_map(static fn (mixed $part): string => is_array($part) ? self::textAt($part, 'text') : '', is_array($content) ? $content : []);

        return implode("\n", $texts);
    }

    /**
     * @return array<string, string>
     */
    public static function failuresIn(string $text): array
    {
        preg_match_all(self::FAILED_TEST, $text, $found, PREG_SET_ORDER);
        $failing = [];

        foreach ($found as $match) {
            $failing[$match[1]] = trim($match[0]);
        }

        return $failing;
    }

    private static function withoutWhitespace(string $text): string
    {
        return (string) preg_replace('~\s+~', '', $text);
    }
}
