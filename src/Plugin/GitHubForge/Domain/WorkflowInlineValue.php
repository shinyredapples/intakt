<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class WorkflowInlineValue
{
    private const string KEY = '/^(?<key>"[^"\\\\]*"|\'[^\']*\'|[A-Za-z0-9_][A-Za-z0-9_.\/-]*) *:(?: +(?<rest>.*))?$/';

    /**
     * @return array{string, string}|null
     */
    public function keyAndRest(string $content): ?array
    {
        if (1 !== preg_match(self::KEY, $content, $match)) {
            return null;
        }

        $key = $match['key'];

        return [str_starts_with($key, '"') || str_starts_with($key, "'") ? substr($key, 1, -1) : $key, $match['rest'] ?? ''];
    }

    public function read(string $text, int $number): WorkflowScalar|WorkflowMapping|WorkflowSequence
    {
        return str_starts_with($text, '[') || str_starts_with($text, '{')
            ? $this->flow($text, $number)
            : new WorkflowScalar($number, $this->scalar($text, $number));
    }

    private function flow(string $text, int $number): WorkflowMapping|WorkflowSequence
    {
        if (1 !== preg_match('/^([\[{])([^\[\]{}#]*)([\]}])(?:\s+#.*)?$/', $text, $match) || ('[' === $match[1]) !== (']' === $match[3])) {
            throw new WorkflowFormNotRead($number, 'a flow value that is nested, holds a comment or goes on over the next line');
        }

        $items = '' === trim($match[2]) ? [] : array_map(trim(...), explode(',', $match[2]));

        return '[' === $match[1]
            ? new WorkflowSequence($number, array_map(fn (string $item): WorkflowScalar => new WorkflowScalar($number, $this->flowScalar($item, $number)), $items))
            : new WorkflowMapping($number, $this->flowEntries($items, $number));
    }

    private function scalar(string $text, int $number): string
    {
        return match (true) {
            str_starts_with($text, '"') => $this->doubleQuoted($text, $number),
            str_starts_with($text, "'") => $this->singleQuoted($text, $number),
            default => $this->plain($text, $number),
        };
    }

    // An escape could spell a context name the scan looks for, so a double-quoted value is read only without one.
    private function doubleQuoted(string $text, int $number): string
    {
        if (1 !== preg_match('/^"([^"\\\\]*)"(?:\s+#.*)?$/', $text, $match)) {
            throw new WorkflowFormNotRead($number, 'a double-quoted value with an escape, or one that goes on over the next line');
        }

        return $match[1];
    }

    private function singleQuoted(string $text, int $number): string
    {
        if (1 !== preg_match("/^'((?:[^']|'')*)'(?:\\s+#.*)?$/", $text, $match)) {
            throw new WorkflowFormNotRead($number, 'a single-quoted value that goes on over the next line');
        }

        return str_replace("''", "'", $match[1]);
    }

    private function plain(string $text, int $number): string
    {
        $value = preg_replace('/\s+#.*$/', '', $text) ?? $text;
        if (1 === preg_match('/^[&*!@`%|>\[\]{}"\']|^[-?:](?:\s|$)|:(?:\s|$)/', $value)) {
            throw new WorkflowFormNotRead($number, 'an anchor, alias, tag or nested key in a value');
        }

        return $value;
    }

    private function flowScalar(string $item, int $number): string
    {
        if ('' === $item) {
            throw new WorkflowFormNotRead($number, 'an empty item in a flow value');
        }

        return $this->scalar($item, $number);
    }

    /**
     * @param list<string> $items
     *
     * @return list<WorkflowEntry>
     */
    private function flowEntries(array $items, int $number): array
    {
        $entries = [];
        foreach ($items as $item) {
            [$key, $rest] = $this->keyAndRest($item) ?? throw new WorkflowFormNotRead($number, 'a flow entry the scan cannot read as a key and its value');
            if (array_key_exists($key, $entries)) {
                throw new WorkflowFormNotRead($number, sprintf('the key "%s" appears twice', $key));
            }

            $entries[$key] = new WorkflowEntry($key, $number, new WorkflowScalar($number, $this->flowScalar($rest, $number)));
        }

        return array_values($entries);
    }
}
