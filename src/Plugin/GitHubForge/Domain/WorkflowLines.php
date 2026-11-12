<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Built per file, because it keeps whether the current line is inside a block scalar.
#[Exclude]
final class WorkflowLines
{
    private const string KEY = '/^(?<key>[A-Za-z0-9_][A-Za-z0-9_.\/-]*):(?:\s+(?<value>.*))?$/';
    private const string ITEM = '/^-(?: +|$)/';
    // YAML also breaks lines at these, so a line holding one would hide lines from the scan.
    private const string OTHER_LINE_BREAK = '/[\r\x{85}\x{2028}\x{2029}]/u';
    // An unclosed quote runs to the line's end, so a # after it stays text and the unclosed value is refused later.
    private const string SEGMENT = '/"(?:[^"\\\\]|\\\\.)*"?|\'(?:[^\']|\'\')*\'?|[^"\']+/';
    private const string COMMENT = '/(?<!\S)#/';

    private bool $started = false;
    private ?int $blockParent = null;
    private ?int $blockContent = null;

    /** @return list<WorkflowLine> */
    public function read(string $content): array
    {
        $lines = [];
        foreach (explode("\n", $content) as $index => $text) {
            $lines[] = $this->line($index + 1, str_ends_with($text, "\r") ? substr($text, 0, -1) : $text);
        }

        return array_values(array_filter($lines));
    }

    private function line(int $number, string $text): ?WorkflowLine
    {
        if (1 === preg_match(self::OTHER_LINE_BREAK, $text)) {
            return WorkflowLine::unreadable($number, $text, 'a line break other than a newline');
        }
        if ($this->isBlockContent($text)) {
            return WorkflowLine::scriptText($number, $text);
        }
        $indent = $this->indentOf($text);
        $content = rtrim($this->withoutComment(substr($text, $indent)), " \t");
        if ('' === $content || ('---' === $content && 0 === $indent && !$this->started)) {
            return null;
        }
        $this->started = true;
        if ("\t" === $content[0]) {
            return WorkflowLine::unreadable($number, $text, 'a tab in the indentation');
        }

        return $this->nodeLine($number, $text, $this->node($indent, $content));
    }

    private function nodeLine(int $number, string $text, ?WorkflowNode $node): WorkflowLine
    {
        if (!$node instanceof WorkflowNode) {
            return WorkflowLine::unreadable($number, $text, 'a line that is neither a plain key nor a list item');
        }
        if ($node->value->startsBlockScalar()) {
            $this->blockParent = $node->indent;
        }

        return WorkflowLine::node($number, $text, $node);
    }

    private function node(int $indent, string $content): ?WorkflowNode
    {
        $isItem = false;
        while (1 === preg_match(self::ITEM, $content, $dash)) {
            $isItem = true;
            $indent += strlen($dash[0]);
            $content = substr($content, strlen($dash[0]));
        }
        if (1 === preg_match(self::KEY, $content, $match)) {
            return new WorkflowNode($indent, $match['key'], new WorkflowValue(trim($match['value'] ?? '')));
        }

        return $isItem && '' !== $content ? new WorkflowNode($indent, null, new WorkflowValue($content)) : null;
    }

    // The same rule as YAML's (deeper than the key, and at least as deep as the first text line), because a line the scan skips as script text must be script text to GitHub too.
    private function isBlockContent(string $text): bool
    {
        if (null === $this->blockParent) {
            return false;
        }
        if ('' === trim($text)) {
            return true;
        }
        $indent = $this->indentOf($text);
        if ($indent > $this->blockParent && $indent >= ($this->blockContent ??= $indent)) {
            return true;
        }
        $this->blockParent = null;
        $this->blockContent = null;

        return false;
    }

    // Quote-aware, because a # inside a quoted value is text; a quote inside a plain value only keeps more text, never less.
    private function withoutComment(string $content): string
    {
        preg_match_all(self::SEGMENT, $content, $segments);
        $kept = '';
        foreach ($segments[0] as $segment) {
            $line = $kept.$segment;
            if (!in_array($segment[0], ['"', "'"], true) && 1 === preg_match(self::COMMENT, $line, $comment, PREG_OFFSET_CAPTURE, strlen($kept))) {
                return substr($line, 0, $comment[0][1]);
            }
            $kept = $line;
        }

        return $kept;
    }

    private function indentOf(string $text): int
    {
        return strlen($text) - strlen(ltrim($text, ' '));
    }
}
