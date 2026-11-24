<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because whether a `#` starts a comment depends on the quoted and block scalars open around it, the lexer keeps the place of the current line's node for one source.
final class YamlLexer
{
    private const string BYTE_ORDER_MARK = "\u{FEFF}";
    private const string BLANKS = " \t";
    private const string SEPARATORS = " \t\r\n";
    // Because only these characters can end a plain scalar or stand before a comment, the text between them is skipped at once.
    private const string PLAIN_BREAKS = " \t\r\n:,]}";
    // Because an anchor name and a tag hold no flow indicator, `[&empty]` closes its sequence right after the anchor.
    private const string PROPERTY_BREAKS = " \t\r\n,[]{}";
    private const string BLOCK_HEADER_INDICATORS = '123456789+-';

    private readonly int $length;
    private int $lineStart = 0;
    // Because a mapping key may stand after `- ` entries, anchors and tags, the node of a line starts after its entries, not at its indentation.
    private int $nodeColumn = 0;
    // Because a block scalar's body is the lines indented deeper than the node that holds it, the lexer keeps that node's column for the next scalar.
    private int $blockParent = -1;
    private bool $atScalarStart = true;
    private int $flowDepth = 0;

    /**
     * @var list<array{int, string}>
     */
    private array $comments = [];

    public function __construct(private readonly string $source)
    {
        $this->length = strlen($source);
    }

    /**
     * @return list<array{int, string}>
     */
    public function comments(): array
    {
        $offset = $this->afterLineBreak(str_starts_with($this->source, self::BYTE_ORDER_MARK) ? strlen(self::BYTE_ORDER_MARK) : 0);

        while ($offset < $this->length) {
            $offset = $this->afterToken($offset);
        }

        return $this->comments;
    }

    private function afterLineBreak(int $offset): int
    {
        $indent = strspn($this->source, ' ', $offset);
        $this->lineStart = $offset;
        $this->nodeColumn = $indent;
        // Because a node alone on its line is a child of a node indented less, its block scalar may start one column further in.
        $this->blockParent = $indent - 1;
        $this->atScalarStart = true;

        return $offset + $indent;
    }

    private function afterToken(int $offset): int
    {
        $char = $this->source[$offset];

        return match (true) {
            "\n" === $char => $this->afterLineBreak($offset + 1),
            '#' === $char && $this->startsAWord($offset) => $this->afterComment($offset),
            str_contains(self::SEPARATORS, $char) => $offset + 1,
            $this->atScalarStart => $this->afterNodeStart($offset, $char),
            default => $this->afterPlain($offset, $char),
        };
    }

    // Because YAML needs white space before a comment, a `#` joined to the text before it is part of a scalar.
    private function startsAWord(int $offset): bool
    {
        return $offset === $this->lineStart || str_contains(self::SEPARATORS, $this->source[$offset - 1]);
    }

    private function afterComment(int $offset): int
    {
        $end = $offset + strcspn($this->source, "\n", $offset);
        $this->comments[] = [$offset, substr($this->source, $offset, $end - $offset)];

        return $end;
    }

    // Because a quote or a block indicator opens a scalar only where a node starts, `don't` in a plain value opens nothing.
    private function afterNodeStart(int $offset, string $char): int
    {
        return match (true) {
            "'" === $char => $this->afterScalar($this->afterSingleQuoted($offset + 1)),
            '"' === $char => $this->afterScalar($this->afterDoubleQuoted($offset + 1)),
            '&' === $char, '!' === $char => $offset + 1 + strcspn($this->source, self::PROPERTY_BREAKS, $offset + 1),
            '[' === $char, '{' === $char => $this->afterFlowOpening($offset),
            0 === $this->flowDepth && ('|' === $char || '>' === $char) => $this->afterBlockScalar($offset),
            $this->isEntryIndicator($offset, $char) => $this->afterEntryIndicator($offset),
            default => $this->afterPlain($offset, $char),
        };
    }

    private function afterPlain(int $offset, string $char): int
    {
        $this->atScalarStart = false;

        return match (true) {
            ':' === $char && $this->isValueIndicator($offset) => $this->afterValueIndicator($offset),
            0 < $this->flowDepth && ',' === $char => $this->afterFlowSeparator($offset),
            0 < $this->flowDepth && (']' === $char || '}' === $char) => $this->afterFlowClosing($offset),
            default => $offset + 1 + strcspn($this->source, self::PLAIN_BREAKS, $offset + 1),
        };
    }

    private function afterSingleQuoted(int $offset): int
    {
        while (false !== $end = strpos($this->source, "'", $offset)) {
            if ("'" !== ($this->source[$end + 1] ?? '')) {
                return $end + 1;
            }

            $offset = $end + 2;
        }

        return $this->length;
    }

    private function afterScalar(int $end): int
    {
        $this->atScalarStart = false;

        return $end;
    }

    private function afterDoubleQuoted(int $offset): int
    {
        $offset += strcspn($this->source, '"\\', $offset);

        while ('\\' === ($this->source[$offset] ?? '')) {
            $offset = min($offset + 2, $this->length);
            $offset += strcspn($this->source, '"\\', $offset);
        }

        return min($offset + 1, $this->length);
    }

    private function afterFlowOpening(int $offset): int
    {
        ++$this->flowDepth;

        return $offset + 1;
    }

    private function afterBlockScalar(int $offset): int
    {
        $indicators = strspn($this->source, self::BLOCK_HEADER_INDICATORS, $offset + 1, 2);
        $indentation = (int) trim(substr($this->source, $offset + 1, $indicators), '+-');
        $parent = $this->blockParent;

        return $this->afterBody($this->afterHeaderComment($offset + 1 + $indicators) + 1, $parent, $indentation);
    }

    private function isEntryIndicator(int $offset, string $char): bool
    {
        return ('-' === $char || '?' === $char) && $this->isFollowedBySeparator($offset);
    }

    // Because a block scalar right after `- ` belongs to the entry, its body is indented deeper than the dash, while a key after it starts the entry's node.
    private function afterEntryIndicator(int $offset): int
    {
        $node = $offset + 1 + strspn($this->source, self::BLANKS, $offset + 1);
        $this->blockParent = $offset - $this->lineStart;
        $this->nodeColumn = $node - $this->lineStart;

        return $node;
    }

    // Because a JSON-like flow mapping may join a value to its key (`{"a":"b"}`), any `:` inside a flow collection ends a key.
    private function isValueIndicator(int $offset): bool
    {
        return 0 < $this->flowDepth || $this->isFollowedBySeparator($offset);
    }

    private function afterValueIndicator(int $offset): int
    {
        $this->blockParent = $this->nodeColumn;
        $this->atScalarStart = true;

        return $offset + 1;
    }

    private function afterFlowSeparator(int $offset): int
    {
        $this->atScalarStart = true;

        return $offset + 1;
    }

    private function afterFlowClosing(int $offset): int
    {
        --$this->flowDepth;

        return $offset + 1;
    }

    private function afterHeaderComment(int $offset): int
    {
        $blanks = strspn($this->source, self::BLANKS, $offset);

        if (0 < $blanks && '#' === ($this->source[$offset + $blanks] ?? '')) {
            return $this->afterComment($offset + $blanks);
        }

        return $offset + strcspn($this->source, "\n", $offset);
    }

    // Because the first line that is not blank fixes the body's indentation unless the header names it, the body ends at the first line indented less.
    private function afterBody(int $offset, int $parent, int $indentation): int
    {
        $content = 0 < $indentation ? $parent + $indentation : null;

        while ($offset < $this->length) {
            $indent = strspn($this->source, ' ', $offset);
            $lineEnd = $offset + strcspn($this->source, "\n", $offset);
            if (!$this->isBlank($offset + $indent, $lineEnd)) {
                $content ??= $indent;

                if ($indent < $content || $content <= $parent) {
                    return $this->afterLineBreak($offset);
                }
            }

            $offset = $lineEnd + 1;
        }

        return $this->length;
    }

    private function isFollowedBySeparator(int $offset): bool
    {
        return str_contains(self::SEPARATORS, $this->source[$offset + 1] ?? "\n");
    }

    private function isBlank(int $offset, int $lineEnd): bool
    {
        return $offset + strspn($this->source, " \t\r", $offset) >= $lineEnd;
    }
}
