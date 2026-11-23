<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

// Reads only the YAML forms workflows commonly use, line by line, because any other form (an anchor, a tag, a value over several lines, a nested flow value) could hide a runner, a permission or a secret from the scan.
final readonly class WorkflowReader
{
    public function __construct(
        private WorkflowInlineValue $inline,
        private WorkflowBlockValue $blocks,
    ) {
    }

    public function workflow(string $text): WorkflowMapping
    {
        $lines = $this->lines($text);
        $first = $this->peek($lines, 0) ?? throw new WorkflowFormNotRead(1, 'the file holds no workflow');
        if ('---' === $first->content) {
            $first = $this->peek($lines, $first->number) ?? throw new WorkflowFormNotRead($first->number, 'the file holds no workflow');
        }

        if (0 !== $first->indent) {
            throw new WorkflowFormNotRead($first->number, 'the workflow does not start at the left margin');
        }

        [$workflow] = $this->collection($lines, $first);
        if (!$workflow instanceof WorkflowMapping) {
            throw new WorkflowFormNotRead($first->number, 'the workflow is not a mapping of keys');
        }

        return $workflow;
    }

    /**
     * @return list<string>
     */
    private function lines(string $text): array
    {
        $text = str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text;
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new WorkflowFormNotRead(1, 'the file is not UTF-8 text');
        }

        // YAML 1.1 also breaks lines at these, so a line the scan reads as one could be two for another reader.
        if (1 === preg_match('/[\x{85}\x{2028}\x{2029}\x{FEFF}\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text, $match, PREG_OFFSET_CAPTURE)) {
            throw new WorkflowFormNotRead(substr_count($text, "\n", 0, $match[0][1]) + 1, 'a control character or an unusual line break');
        }

        return explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * @param list<string> $lines
     */
    private function peek(array $lines, int $from): ?WorkflowLine
    {
        for ($index = $from; $index < count($lines); ++$index) {
            $line = $this->line($lines[$index], $index + 1);
            if ('' !== $line->content && !str_starts_with($line->content, '#')) {
                return $line;
            }
        }

        return null;
    }

    private function line(string $text, int $number): WorkflowLine
    {
        $raw = rtrim($text, " \t");
        $content = ltrim($raw, ' ');
        if (str_starts_with($content, "\t")) {
            throw new WorkflowFormNotRead($number, 'a tab in the indentation');
        }

        return new WorkflowLine($number, strlen($raw) - strlen($content), $content);
    }

    /**
     * @param list<string> $lines
     *
     * @return array{WorkflowMapping|WorkflowSequence, int}
     */
    private function collection(array $lines, WorkflowLine $first): array
    {
        return $this->isItem($first->content) ? $this->sequence($lines, $first) : $this->mapping($lines, $first);
    }

    private function isItem(string $content): bool
    {
        return '-' === $content || str_starts_with($content, '- ') || str_starts_with($content, "-\t");
    }

    /**
     * @param list<string> $lines
     *
     * @return array{WorkflowSequence, int}
     */
    private function sequence(array $lines, WorkflowLine $first): array
    {
        $items = [];
        $line = $first;
        $next = $first->number;
        while ($line instanceof WorkflowLine && $line->indent === $first->indent && $this->isItem($line->content)) {
            [$items[], $next] = $this->item($lines, $line);
            $line = $this->peek($lines, $next);
        }

        if ($line instanceof WorkflowLine && $line->indent > $first->indent) {
            throw new WorkflowFormNotRead($line->number, 'a line that belongs to no item above it');
        }

        return [new WorkflowSequence($first->number, $items), $next];
    }

    /**
     * @param list<string> $lines
     *
     * @return array{WorkflowMapping, int}
     */
    private function mapping(array $lines, WorkflowLine $first): array
    {
        $entries = [];
        $line = $first;
        $next = $first->number;
        while ($line instanceof WorkflowLine && $line->indent === $first->indent && !$this->isItem($line->content)) {
            [$entry, $next] = $this->entry($lines, $line);
            if (array_key_exists($entry->key, $entries)) {
                throw new WorkflowFormNotRead($line->number, sprintf('the key "%s" appears twice', $entry->key));
            }

            $entries[$entry->key] = $entry;
            $line = $this->peek($lines, $next);
        }

        if ($line instanceof WorkflowLine && $line->indent >= $first->indent) {
            throw new WorkflowFormNotRead($line->number, 'a line that belongs to no key above it');
        }

        return [new WorkflowMapping($first->number, array_values($entries)), $next];
    }

    /**
     * @param list<string> $lines
     *
     * @return array{WorkflowEntry, int}
     */
    private function entry(array $lines, WorkflowLine $line): array
    {
        [$key, $rest] = $this->inline->keyAndRest($line->content) ?? throw new WorkflowFormNotRead($line->number, 'a line the scan cannot read as a key and its value');
        [$value, $next] = $this->isEmpty($rest) ? $this->entryChild($lines, $line) : $this->value($lines, $rest, $line);

        return [new WorkflowEntry($key, $line->number, $value), $next];
    }

    /**
     * @param list<string> $lines
     *
     * @return array{WorkflowScalar|WorkflowMapping|WorkflowSequence, int}
     */
    private function item(array $lines, WorkflowLine $line): array
    {
        $rest = substr($line->content, 1);
        $content = ltrim($rest, ' ');
        if (str_starts_with($content, "\t")) {
            throw new WorkflowFormNotRead($line->number, 'a tab after a list dash');
        }

        if ($this->isItem($content) || null !== $this->inline->keyAndRest($content)) {
            return $this->collection($lines, new WorkflowLine($line->number, $line->indent + 1 + strlen($rest) - strlen($content), $content));
        }

        return $this->isEmpty($content) ? $this->itemChild($lines, $line) : $this->value($lines, $content, $line);
    }

    /**
     * @param list<string> $lines
     *
     * @return array{WorkflowScalar|WorkflowMapping|WorkflowSequence, int}
     */
    private function itemChild(array $lines, WorkflowLine $line): array
    {
        $next = $this->peek($lines, $line->number);

        return $next instanceof WorkflowLine && $next->indent > $line->indent ? $this->collection($lines, $next) : [new WorkflowScalar($line->number, ''), $line->number];
    }

    private function isEmpty(string $rest): bool
    {
        return '' === $rest || str_starts_with($rest, '#');
    }

    /**
     * @param list<string> $lines
     *
     * @return array{WorkflowScalar|WorkflowMapping|WorkflowSequence, int}
     */
    private function entryChild(array $lines, WorkflowLine $line): array
    {
        $next = $this->peek($lines, $line->number);
        if ($next instanceof WorkflowLine && $next->indent > $line->indent) {
            return $this->collection($lines, $next);
        }

        // YAML lets a list under a key start at the key's own indentation, so such items must stay under the key.
        if ($next instanceof WorkflowLine && $next->indent === $line->indent && $this->isItem($next->content)) {
            return $this->sequence($lines, $next);
        }

        return [new WorkflowScalar($line->number, ''), $line->number];
    }

    /**
     * @param list<string> $lines
     *
     * @return array{WorkflowScalar|WorkflowMapping|WorkflowSequence, int}
     */
    private function value(array $lines, string $rest, WorkflowLine $line): array
    {
        if (str_starts_with($rest, '|') || str_starts_with($rest, '>')) {
            return $this->blockValue($lines, $rest, $line);
        }

        $value = $this->inline->read($rest, $line->number);
        $next = $this->peek($lines, $line->number);
        if ($next instanceof WorkflowLine && $next->indent > $line->indent) {
            throw new WorkflowFormNotRead($next->number, 'a value that goes on over the next line');
        }

        return [$value, $line->number];
    }

    /**
     * @param list<string> $lines
     *
     * @return array{WorkflowScalar, int}
     */
    private function blockValue(array $lines, string $header, WorkflowLine $line): array
    {
        if (1 !== preg_match('/^[|>][+-]?(?:\s+#.*)?$/', $header)) {
            throw new WorkflowFormNotRead($line->number, 'a block value with an indentation indicator');
        }

        return $this->blocks->read($lines, $line);
    }
}
