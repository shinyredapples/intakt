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
        if (preg_match(self::OTHER_LINE_BREAK, $text) === 1) {
            return WorkflowLine::unreadable($number, $text, 'a line break other than a newline');
        }
        if ($this->isBlockContent($text)) {
            return WorkflowLine::scriptText($number, $text);
        }
        $indent = $this->indentOf($text);
        $content = rtrim($this->withoutComment(substr($text, $indent)), " \t");
        if ($content === '' || ($content === '---' && $indent === 0 && !$this->started)) {
            return null;
        }
        $this->started = true;
        if ($content[0] === "\t") {
            return WorkflowLine::unreadable($number, $text, 'a tab in the indentation');
        }

        return $this->nodeLine($number, $text, $this->node($indent, $content));
    }

    private function nodeLine(int $number, string $text, ?WorkflowNode $node): WorkflowLine
    {
        if ($node === null) {
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
        while (preg_match(self::ITEM, $content, $dash) === 1) {
            $isItem = true;
            $indent += strlen($dash[0]);
            $content = substr($content, strlen($dash[0]));
        }
        if (preg_match(self::KEY, $content, $match) === 1) {
            return new WorkflowNode($indent, $match['key'], new WorkflowValue(trim($match['value'] ?? '')));
        }

        return $isItem && $content !== '' ? new WorkflowNode($indent, null, new WorkflowValue($content)) : null;
    }

    // The same rule as YAML's (deeper than the key, and at least as deep as the first text line), because a line the scan skips as script text must be script text to GitHub too.
    private function isBlockContent(string $text): bool
    {
        if ($this->blockParent === null) {
            return false;
        }
        if (trim($text) === '') {
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
        $quote = null;
        $length = strlen($content);
        for ($at = 0; $at < $length; ++$at) {
            $char = $content[$at];
            if ($quote === '"' && $char === '\\') {
                ++$at;
            } elseif ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '#' && ($at === 0 || ctype_space($content[$at - 1]))) {
                return substr($content, 0, $at);
            }
        }

        return $content;
    }

    private function indentOf(string $text): int
    {
        return strlen($text) - strlen(ltrim($text, ' '));
    }
}
