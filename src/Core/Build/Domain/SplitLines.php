<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

// The one form plain code reads a split in, so a model's split never names a dependency by title or reaches past the parts it made: docs/design/05-architecture/03-choosing-work.md (step 3).
final readonly class SplitLines
{
    public const int FEWEST = 2;
    public const int MOST = 4;
    // A part is never split again, so a chain a build cannot finish in two steps must be cut differently instead.
    public const int LONGEST_CHAIN = 2;
    private const array MARKERS = [' | done-when: ', ' | verify: ', ' | files: ', ' | depends on: '];
    private const string NO_DEPENDENCY = 'none';
    private const string DEPENDENCIES = '/\Apart [1-9][0-9]?(?:, part [1-9][0-9]?)*\z/';

    // Null for any line out of form, because a split plain code cannot read whole would file parts that wait for the wrong work.
    /**
     * @param list<string> $lines
     *
     * @return list<SplitPart>|null
     */
    public static function partsIn(array $lines): ?array
    {
        if (count($lines) < self::FEWEST || count($lines) > self::MOST) {
            return null;
        }

        $parts = [];

        foreach ($lines as $index => $line) {
            $part = self::partIn($index + 1, $line);

            if (!$part instanceof SplitPart) {
                return null;
            }

            $parts[] = $part;
        }

        return self::longestChainIn($parts) <= self::LONGEST_CHAIN ? $parts : null;
    }

    private static function partIn(int $number, string $line): ?SplitPart
    {
        // A line break or an HTML comment in a part would let model text start a `Waits for` line, open an external block or forge a part marker in the filed issue.
        if (1 === preg_match('/[\r\n]/', $line) || str_contains($line, '<!--')) {
            return null;
        }

        $fields = self::fieldsIn($line);

        if (null === $fields) {
            return null;
        }

        $dependsOn = self::dependenciesIn($number, $fields[4]);

        return null === $dependsOn ? null : new SplitPart($number, $fields[0], $fields[1], $fields[2], $fields[3], $dependsOn);
    }

    // Each marker exactly once and in order, so a field's text runs to the next marker and a ` | ` inside a field stays part of it.
    /**
     * @return array{string, string, string, string, string}|null
     */
    private static function fieldsIn(string $line): ?array
    {
        $fields = [];
        $rest = $line;

        foreach (self::MARKERS as $marker) {
            if (1 !== substr_count($line, $marker)) {
                return null;
            }

            $at = strpos($rest, $marker);

            if (false === $at) {
                return null;
            }

            $fields[] = trim(substr($rest, 0, $at));
            $rest = substr($rest, $at + strlen($marker));
        }

        $fields[] = trim($rest);

        return in_array('', $fields, true) ? null : [$fields[0], $fields[1], $fields[2], $fields[3], $fields[4]];
    }

    /**
     * @return list<int>|null
     */
    private static function dependenciesIn(int $number, string $field): ?array
    {
        if (self::NO_DEPENDENCY === $field) {
            return [];
        }

        if (1 !== preg_match(self::DEPENDENCIES, $field)) {
            return null;
        }

        $named = array_map(static fn (string $reference): int => (int) substr($reference, strlen('part ')), explode(', ', $field));
        $earlier = array_filter($named, static fn (int $part): bool => $part < $number);

        return count($earlier) === count($named) && count(array_unique($named)) === count($named) ? $named : null;
    }

    /**
     * @param list<SplitPart> $parts
     */
    private static function longestChainIn(array $parts): int
    {
        $chains = [];

        foreach ($parts as $part) {
            $chains[$part->number] = 1 + max([0, ...array_map(static fn (int $earlier): int => $chains[$earlier], $part->dependsOn)]);
        }

        return max($chains);
    }
}
