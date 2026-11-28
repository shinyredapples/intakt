<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// What the CLI's stream-json events of a running build show, because neither the clone nor the test runs are visible from outside the container; every field is untrusted, so a malformed event is skipped.
#[Exclude]
final readonly class BuildLogReading
{
    private const array USAGE_COUNTS = ['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens'];

    private const string TEST_RUN = '~phpunit|bin/check|composer\s+(?:test|check)~';

    private const string FAILED_TEST = '~^\s*\d+\) (\S+::\S+)~m';

    private const array EDIT_TOOLS = ['Edit', 'Write', 'MultiEdit'];

    /**
     * @param array<string, string> $failingTests
     */
    private function __construct(
        public int $tokens,
        public string $contentFingerprint,
        public array $failingTests,
        public bool $checkRunning,
    ) {
    }

    public static function of(string $log): self
    {
        $perMessage = [];
        $resultTokens = 0;
        $changes = [];
        $open = [];
        $failing = [];

        foreach (explode("\n", $log) as $line) {
            $event = '{' === ($line[0] ?? '') ? json_decode($line, true) : null;

            if (!is_array($event)) {
                continue;
            }

            if ('result' === ($event['type'] ?? null)) {
                $resultTokens = max($resultTokens, self::tokensOf($event['usage'] ?? null));
            }

            $message = is_array($event['message'] ?? null) ? $event['message'] : [];

            if ('assistant' === ($event['type'] ?? null)) {
                // Keyed by the message id, because one message arrives once for each of its content blocks with the same usage.
                $perMessage[is_string($message['id'] ?? null) ? $message['id'] : 'line-'.count($perMessage)] = self::tokensOf($message['usage'] ?? null);
            }

            foreach (is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
                if (!is_array($block)) {
                    continue;
                }

                self::take($block, $open, $changes, $failing);
            }
        }

        return new self(
            max($resultTokens, array_sum($perMessage)),
            hash('sha256', implode("\0", $changes)),
            $failing,
            array_any($open, static fn (bool $isTestRun): bool => $isTestRun),
        );
    }

    /**
     * @param array<mixed>      $block
     * @param array<string,bool> $open    tool call id => whether it runs the tests or the checks
     * @param list<string>      $changes
     * @param array<string,string> $failing
     */
    private static function take(array $block, array &$open, array &$changes, array &$failing): void
    {
        $id = is_string($block['id'] ?? null) ? $block['id'] : null;

        if ('tool_use' === ($block['type'] ?? null) && null !== $id) {
            $input = is_array($block['input'] ?? null) ? $block['input'] : [];
            $command = is_string($input['command'] ?? null) ? $input['command'] : '';
            $open[$id] = 'Bash' === ($block['name'] ?? null) && 1 === preg_match(self::TEST_RUN, $command);
            $change = self::changeOf(is_string($block['name'] ?? null) ? $block['name'] : '', $input, $command);

            if ('' !== $change) {
                $changes[] = $change;
            }

            return;
        }

        $used = is_string($block['tool_use_id'] ?? null) ? $block['tool_use_id'] : null;

        if ('tool_result' !== ($block['type'] ?? null) || null === $used || !isset($open[$used])) {
            return;
        }

        if ($open[$used]) {
            $failing = self::failuresIn(self::textOf($block['content'] ?? null));
        }
        unset($open[$used]);
    }

    /**
     * @param array<mixed> $input
     */
    private static function changeOf(string $tool, array $input, string $command): string
    {
        if ('Bash' === $tool) {
            return 1 === preg_match('~\bgit\s+commit\b~', $command) && !str_contains($command, '--allow-empty') ? 'commit:'.self::withoutWhitespace($command) : '';
        }

        if (!in_array($tool, self::EDIT_TOOLS, true)) {
            return '';
        }

        $before = self::withoutWhitespace(is_string($input['old_string'] ?? null) ? $input['old_string'] : '');
        $after = self::withoutWhitespace(is_string($input['new_string'] ?? $input['content'] ?? null) ? ($input['new_string'] ?? $input['content']) : '');

        return $before === $after ? '' : 'edit:'.$before.'>'.$after;
    }

    private static function withoutWhitespace(string $text): string
    {
        return (string) preg_replace('~\s+~', '', $text);
    }

    private static function textOf(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        $texts = [];
        foreach (is_array($content) ? $content : [] as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $texts[] = $part['text'];
            }
        }

        return implode("\n", $texts);
    }

    /**
     * @return array<string, string>
     */
    private static function failuresIn(string $text): array
    {
        preg_match_all(self::FAILED_TEST, $text, $found, PREG_SET_ORDER);
        $failing = [];

        foreach ($found as $match) {
            $failing[$match[1]] = trim($match[0]);
        }

        return $failing;
    }

    // Counted as the Anthropic form counts them, cache reads and writes apart from the input, so every figure of the usage is added.
    private static function tokensOf(mixed $usage): int
    {
        if (!is_array($usage)) {
            return 0;
        }

        $tokens = 0;
        foreach (self::USAGE_COUNTS as $name) {
            $count = $usage[$name] ?? 0;
            $tokens += is_int($count) && $count > 0 ? $count : 0;
        }

        return $tokens;
    }
}
