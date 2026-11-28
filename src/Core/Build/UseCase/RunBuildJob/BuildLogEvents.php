<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

// Reads the shapes of the CLI's stream-json events, none of which can be trusted: a field of another type counts as absent.
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
            $tokens += is_int($count) && $count > 0 ? $count : 0;
        }

        return $tokens;
    }

    public static function runsTests(string $tool, string $command): bool
    {
        return 'Bash' === $tool && 1 === preg_match(self::TEST_RUN, $command);
    }

    /**
     * @param array<mixed> $input
     */
    public static function changeBy(string $tool, array $input): string
    {
        $command = self::textAt($input, 'command');

        if ('Bash' === $tool) {
            return 1 === preg_match('~\bgit\s+commit\b~', $command) && !str_contains($command, '--allow-empty') ? 'commit:'.self::withoutWhitespace($command) : '';
        }

        $before = self::withoutWhitespace(self::textAt($input, 'old_string'));
        // A MultiEdit carries its changes in a list of its own.
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
