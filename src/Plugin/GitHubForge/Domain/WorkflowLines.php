<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

// Reads only the YAML forms workflows commonly use, line by line, because any other form (an anchor, a tag, a value over several lines, a nested flow value) could hide a runner, a permission or a secret from the scan.
final class WorkflowLines
{
    /** @var list<string> */
    private readonly array $lines;

    private int $next = 0;

    /** @var array<int, WorkflowLine> */
    private array $itemContents = [];

    public function __construct(
        string $text,
        private readonly WorkflowInlineValue $inline,
        private readonly WorkflowBlockValue $blocks,
    ) {
        $text = str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text;
        $this->refuseUnreadableCharacters($text);
        $this->lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
    }

    public function workflow(): WorkflowMapping
    {
        if ('---' === $this->peek()?->content) {
            ++$this->next;
        }

        $first = $this->peek() ?? throw new WorkflowFormNotRead(1, 'the file holds no workflow');
        if (0 !== $first->indent) {
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
        if (1 === preg_match('/[\x{85}\x{2028}\x{2029}\x{FEFF}\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text, $match, PREG_OFFSET_CAPTURE)) {
            throw new WorkflowFormNotRead(substr_count($text, "\n", 0, $match[0][1]) + 1, 'a control character or an unusual line break');
        }
    }

    private function peek(): ?WorkflowLine
    {
        while ($this->next < count($this->lines)) {
            $line = $this->itemContents[$this->next] ?? $this->line($this->next);
            if ('' !== $line->content && !str_starts_with($line->content, '#')) {
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
        while (null !== $line && $line->indent === $first->indent && !$this->isItem($line->content)) {
            $entry = $this->entry($line);
            if (array_key_exists($entry->key, $entries)) {
                throw new WorkflowFormNotRead($line->number, sprintf('the key "%s" appears twice', $entry->key));
            }

            $entries[$entry->key] = $entry;
            $line = $this->peek();
        }

        if (null !== $line && $line->indent >= $first->indent) {
            throw new WorkflowFormNotRead($line->number, 'a line that belongs to no key above it');
        }

        return new WorkflowMapping($first->number, array_values($entries));
    }

    private function entry(WorkflowLine $line): WorkflowEntry
    {
        [$key, $rest] = $this->inline->keyAndRest($line->content) ?? throw new WorkflowFormNotRead($line->number, 'a line the scan cannot read as a key and its value');
        ++$this->next;

        return new WorkflowEntry($key, $line->number, $this->isEmpty($rest) ? $this->entryChild($line) : $this->value($rest, $line));
    }

    // YAML lets a list under a key start at the key's own indentation, so such items must stay under the key.
    private function entryChild(WorkflowLine $line): WorkflowScalar|WorkflowMapping|WorkflowSequence
    {
        $next = $this->peek();
        if (null !== $next && $next->indent > $line->indent) {
            return $this->collection($next);
        }

        if (null !== $next && $next->indent === $line->indent && $this->isItem($next->content)) {
            return $this->sequence($next);
        }

        return new WorkflowScalar($line->number, '');
    }

    private function sequence(WorkflowLine $first): WorkflowSequence
    {
        $items = [];
        $line = $first;
        while (null !== $line && $line->indent === $first->indent && $this->isItem($line->content)) {
            $items[] = $this->item($line);
            $line = $this->peek();
        }

        if (null !== $line && $line->indent > $first->indent) {
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

        if ($this->isItem($content) || null !== $this->inline->keyAndRest($content)) {
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

        return null !== $next && $next->indent > $line->indent ? $this->collection($next) : new WorkflowScalar($line->number, '');
    }

    private function value(string $rest, WorkflowLine $line): WorkflowScalar|WorkflowMapping|WorkflowSequence
    {
        if (str_starts_with($rest, '|') || str_starts_with($rest, '>')) {
            return $this->blockValue($rest, $line);
        }

        $value = $this->inline->read($rest, $line->number);
        $next = $this->peek();
        if (null !== $next && $next->indent > $line->indent) {
            throw new WorkflowFormNotRead($next->number, 'a value that goes on over the next line');
        }

        return $value;
    }

    private function blockValue(string $header, WorkflowLine $line): WorkflowScalar
    {
        if (1 !== preg_match('/^[|>][+-]?(?:\s+#.*)?$/', $header)) {
            throw new WorkflowFormNotRead($line->number, 'a block value with an indentation indicator');
        }

        [$value, $this->next] = $this->blocks->read($this->lines, $this->next, $line);

        return $value;
    }

    private function isEmpty(string $rest): bool
    {
        return '' === $rest || str_starts_with($rest, '#');
    }

    private function isItem(string $content): bool
    {
        return '-' === $content || str_starts_with($content, '- ') || str_starts_with($content, "-\t");
    }
}
