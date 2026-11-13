<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

// Reads only the YAML forms workflows commonly use, line by line: any other form (an anchor, a tag, a value over several lines, a nested flow value) could hide a runner, a permission or a secret from the scan, so it is not read.
final class WorkflowLines
{
    private const string KEY = '/^(?<key>"[^"\\\\]*"|\'[^\']*\'|[A-Za-z0-9_][A-Za-z0-9_.\/-]*) *:(?: +(?<rest>.*))?$/';

    /** @var list<string> */
    private readonly array $lines;

    private int $next = 0;

    /** @var array<int, WorkflowLine> */
    private array $itemContents = [];

    public function __construct(string $text)
    {
        $text = str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text;
        $this->refuseUnreadableCharacters($text);
        $this->lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    }

    public function workflow(): WorkflowMapping
    {
        $this->skipDocumentStart();
        $first = $this->peek() ?? throw new WorkflowFormNotRead(1, 'the file holds no workflow');
        if ($first->indent !== 0) {
            throw new WorkflowFormNotRead($first->number, 'the workflow does not start at the left margin');
        }

        $workflow = $this->collection($first);
        if (!$workflow instanceof WorkflowMapping) {
            throw new WorkflowFormNotRead($first->number, 'the workflow is not a mapping of keys');
        }

        return $workflow;
    }

    private function refuseUnreadableCharacters(string $text): void
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new WorkflowFormNotRead(1, 'the file is not UTF-8 text');
        }

        // YAML 1.1 also breaks lines at these, so a line the scan reads as one could be two for another reader.
        if (preg_match('/[\x{85}\x{2028}\x{2029}\x{FEFF}\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text, $match, PREG_OFFSET_CAPTURE) === 1) {
            throw new WorkflowFormNotRead(substr_count($text, "\n", 0, $match[0][1]) + 1, 'a control character or an unusual line break');
        }
    }

    private function skipDocumentStart(): void
    {
        if ($this->peek()?->content === '---') {
            ++$this->next;
        }
    }

    private function peek(): ?WorkflowLine
    {
        while ($this->next < count($this->lines)) {
            $line = $this->itemContents[$this->next] ?? $this->line($this->next);
            if ($line->content !== '' && !str_starts_with($line->content, '#')) {
                return $line;
            }

            ++$this->next;
        }

        return null;
    }

    private function line(int $index): WorkflowLine
    {
        $raw = rtrim($this->lines[$index], " \t");
        $content = ltrim($raw, ' ');
        if (str_starts_with($content, "\t")) {
            throw new WorkflowFormNotRead($index + 1, 'a tab in the indentation');
        }

        return new WorkflowLine($index + 1, strlen($raw) - strlen($content), $content);
    }

    private function collection(WorkflowLine $first): WorkflowMapping|WorkflowSequence
    {
        return $this->isItem($first->content) ? $this->sequence($first) : $this->mapping($first);
    }

    private function mapping(WorkflowLine $first): WorkflowMapping
    {
        $entries = [];
        $line = $first;
        while ($line !== null && $line->indent === $first->indent && !$this->isItem($line->content)) {
            $entry = $this->entry($line);
            if (array_key_exists($entry->key, $entries)) {
                throw new WorkflowFormNotRead($line->number, sprintf('the key "%s" appears twice', $entry->key));
            }

            $entries[$entry->key] = $entry;
            $line = $this->peek();
        }

        if ($line !== null && $line->indent >= $first->indent) {
            throw new WorkflowFormNotRead($line->number, 'a line that belongs to no key above it');
        }

        return new WorkflowMapping($first->number, array_values($entries));
    }

    private function entry(WorkflowLine $line): WorkflowEntry
    {
        if (preg_match(self::KEY, $line->content, $match) !== 1) {
            throw new WorkflowFormNotRead($line->number, 'a line the scan cannot read as a key and its value');
        }

        ++$this->next;
        $rest = $match['rest'] ?? '';

        return new WorkflowEntry($this->unquoted($match['key']), $line->number, $this->isEmpty($rest) ? $this->entryChild($line) : $this->value($rest, $line));
    }

    private function unquoted(string $key): string
    {
        return str_starts_with($key, '"') || str_starts_with($key, "'") ? substr($key, 1, -1) : $key;
    }

    // YAML lets a list under a key start at the key's own indentation.
    private function entryChild(WorkflowLine $line): WorkflowScalar|WorkflowMapping|WorkflowSequence
    {
        $next = $this->peek();
        if ($next !== null && $next->indent > $line->indent) {
            return $this->collection($next);
        }

        if ($next !== null && $next->indent === $line->indent && $this->isItem($next->content)) {
            return $this->sequence($next);
        }

        return new WorkflowScalar($line->number, '');
    }

    private function sequence(WorkflowLine $first): WorkflowSequence
    {
        $items = [];
        $line = $first;
        while ($line !== null && $line->indent === $first->indent && $this->isItem($line->content)) {
            $items[] = $this->item($line);
            $line = $this->peek();
        }

        if ($line !== null && $line->indent > $first->indent) {
            throw new WorkflowFormNotRead($line->number, 'a line that belongs to no item above it');
        }

        return new WorkflowSequence($first->number, $items);
    }

    private function item(WorkflowLine $line): WorkflowScalar|WorkflowMapping|WorkflowSequence
    {
        $rest = substr($line->content, 1);
        $content = ltrim($rest, ' ');
        if (str_starts_with($content, "\t")) {
            throw new WorkflowFormNotRead($line->number, 'a tab after a list dash');
        }

        if ($this->isItem($content) || preg_match(self::KEY, $content) === 1) {
            $nested = new WorkflowLine($line->number, $line->indent + 1 + strlen($rest) - strlen($content), $content);
            $this->itemContents[$this->next] = $nested;

            return $this->collection($nested);
        }

        ++$this->next;

        return $this->isEmpty($content) ? $this->itemChild($line) : $this->value($content, $line);
    }

    private function itemChild(WorkflowLine $line): WorkflowScalar|WorkflowMapping|WorkflowSequence
    {
        $next = $this->peek();

        return $next !== null && $next->indent > $line->indent ? $this->collection($next) : new WorkflowScalar($line->number, '');
    }

    private function value(string $rest, WorkflowLine $line): WorkflowScalar|WorkflowMapping|WorkflowSequence
    {
        if (str_starts_with($rest, '|') || str_starts_with($rest, '>')) {
            return $this->blockScalar($rest, $line);
        }

        $value = $this->inlineValue($rest, $line->number);
        $next = $this->peek();
        if ($next !== null && $next->indent > $line->indent) {
            throw new WorkflowFormNotRead($next->number, 'a value that goes on over the next line');
        }

        return $value;
    }

    private function inlineValue(string $rest, int $number): WorkflowScalar|WorkflowMapping|WorkflowSequence
    {
        return match (true) {
            str_starts_with($rest, '['), str_starts_with($rest, '{') => $this->flow($rest, $number),
            default => new WorkflowScalar($number, $this->scalar($rest, $number)),
        };
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
        if (preg_match('/^"([^"\\\\]*)"(?:\s+#.*)?$/', $text, $match) !== 1) {
            throw new WorkflowFormNotRead($number, 'a double-quoted value with an escape, or one that goes on over the next line');
        }

        return $match[1];
    }

    private function singleQuoted(string $text, int $number): string
    {
        if (preg_match("/^'((?:[^']|'')*)'(?:\\s+#.*)?$/", $text, $match) !== 1) {
            throw new WorkflowFormNotRead($number, 'a single-quoted value that goes on over the next line');
        }

        return str_replace("''", "'", $match[1]);
    }

    private function plain(string $text, int $number): string
    {
        $value = preg_replace('/\s+#.*$/', '', $text) ?? $text;
        if (preg_match('/^[&*!@`%|>\[\]{}"\']|^[-?:](?:\s|$)|:(?:\s|$)/', $value) === 1) {
            throw new WorkflowFormNotRead($number, 'an anchor, alias, tag or nested key in a value');
        }

        return $value;
    }

    private function flow(string $text, int $number): WorkflowMapping|WorkflowSequence
    {
        if (preg_match('/^([\[{])([^\[\]{}#]*)([\]}])(?:\s+#.*)?$/', $text, $match) !== 1 || ($match[1] === '[') !== ($match[3] === ']')) {
            throw new WorkflowFormNotRead($number, 'a flow value that is nested, holds a comment or goes on over the next line');
        }

        $items = trim($match[2]) === '' ? [] : array_map(trim(...), explode(',', $match[2]));

        return $match[1] === '['
            ? new WorkflowSequence($number, array_map(fn (string $item): WorkflowScalar => new WorkflowScalar($number, $this->flowScalar($item, $number)), $items))
            : new WorkflowMapping($number, $this->flowEntries($items, $number));
    }

    private function flowScalar(string $item, int $number): string
    {
        if ($item === '') {
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
            $key = preg_match(self::KEY, $item, $match) === 1 ? $this->unquoted($match['key']) : null;
            if ($key === null || array_key_exists($key, $entries)) {
                throw new WorkflowFormNotRead($number, 'a flow entry the scan cannot read as a key and its value');
            }

            $entries[$key] = new WorkflowEntry($key, $number, new WorkflowScalar($number, $this->flowScalar($match['rest'] ?? '', $number)));
        }

        return array_values($entries);
    }

    private function blockScalar(string $header, WorkflowLine $line): WorkflowScalar
    {
        if (preg_match('/^[|>][+-]?(?:\s+#.*)?$/', $header) !== 1) {
            throw new WorkflowFormNotRead($line->number, 'a block value with an indentation indicator');
        }

        $indent = $this->blockIndent($line);
        $texts = [];
        while ($indent !== null && $this->next < count($this->lines) && $this->insideBlock($indent, $line)) {
            $texts[] = substr($this->lines[$this->next], $indent);
            ++$this->next;
        }

        return new WorkflowScalar($line->number, implode("\n", $texts));
    }

    private function blockIndent(WorkflowLine $line): ?int
    {
        $widestBlank = 0;
        for ($index = $this->next; $index < count($this->lines); ++$index) {
            $lead = $this->lead($this->lines[$index]);
            if ($this->isBlank($this->lines[$index])) {
                $widestBlank = max($widestBlank, $lead);

                continue;
            }

            if ($lead <= $line->indent) {
                return null;
            }

            if ($widestBlank > $lead) {
                throw new WorkflowFormNotRead($index + 1, 'a block value whose leading blank lines are indented deeper than its text');
            }

            return $lead;
        }

        return null;
    }

    private function insideBlock(int $indent, WorkflowLine $line): bool
    {
        $raw = $this->lines[$this->next];
        $lead = $this->lead($raw);
        if ($this->isBlank($raw) || $lead >= $indent) {
            return true;
        }

        if ($lead > $line->indent) {
            throw new WorkflowFormNotRead($this->next + 1, 'a line of a block value indented less than its first line');
        }

        return false;
    }

    private function lead(string $raw): int
    {
        return strlen($raw) - strlen(ltrim($raw, ' '));
    }

    private function isBlank(string $raw): bool
    {
        return trim($raw, " \t") === '';
    }

    private function isEmpty(string $rest): bool
    {
        return $rest === '' || str_starts_with($rest, '#');
    }

    private function isItem(string $content): bool
    {
        return $content === '-' || str_starts_with($content, '- ') || str_starts_with($content, "-\t");
    }
}
