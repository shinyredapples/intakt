<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because a `#` starts a comment only outside quoted, block and plain scalars, the lexer keeps the flow depth, the indentation of the current node, an open plain scalar and a pending block scalar of one source.
final class YamlLexer
{
    private const string BLANKS = " \t\r";
    private const string ENDS_A_PLAIN_RUN = " \t\r\n#:,[]{}";
    private const string BLOCK_SCALAR_HEADER = '~\G[|>]([0-9+-]*+)(?=[ \t\r\n]|\z)~';
    private const string DOCUMENT_MARKER = '~\G(?:---|\.\.\.)(?=[ \t\r\n]|\z)~';
    private const string BYTE_ORDER_MARK = "\u{FEFF}";
    // Because in a flow collection a `:` followed by any other character belongs to a plain scalar, as in `a:'b`.
    private const string ENDS_A_FLOW_KEY = " \t\r\n,[]{}";

    private readonly int $length;
    private bool $valueStarts = true;
    private int $flowDepth = 0;
    private int $lineStart = 0;
    private int $parentIndent = 0;
    private int $nodeColumn = 0;
    private ?int $propertyColumn = null;
    private bool $plainScalarOpen = false;
    private bool $blockScalarFollows = false;
    private ?int $blockScalarIndent = null;
    private bool $jsonNodeEnded = false;

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
        $offset = $this->afterLineStart(str_starts_with($this->source, self::BYTE_ORDER_MARK) ? strlen(self::BYTE_ORDER_MARK) : 0);

        while ($offset < $this->length) {
            $offset = $this->afterToken($offset);
        }

        return $this->comments;
    }

    // Because a line indented deeper than its parent node continues an open plain scalar, a quote at its start is text, while `---` and `...` mark a document only at the start of a line.
    private function afterLineStart(int $offset): int
    {
        $this->lineStart = $offset;
        $indent = strspn($this->source, ' ', $offset);
        $offset += $indent;

        if (str_contains("\r\n", $this->source[$offset] ?? "\n")) {
            return $offset;
        }

        if ($this->plainScalarOpen && (0 < $this->flowDepth || $indent > $this->parentIndent)) {
            return $offset;
        }

        $this->plainScalarOpen = false;
        $this->valueStarts = true;
        $this->parentIndent = $indent;
        $this->propertyColumn = null;

        if (0 === $indent && 1 === preg_match(self::DOCUMENT_MARKER, $this->source, $marker, 0, $offset)) {
            return $offset + 3;
        }

        return $offset;
    }

    private function afterToken(int $offset): int
    {
        $char = $this->source[$offset];

        return match (true) {
            "\n" === $char, $this->isABareCarriageReturn($offset) => $this->afterLineEnd($offset + 1),
            str_contains(self::BLANKS, $char) => $offset + strspn($this->source, self::BLANKS, $offset),
            '#' === $char && $this->followsABreak($offset) => $this->afterComment($offset),
            $this->valueStarts => $this->afterValueStart($offset, $char),
            default => $this->afterPlainCharacter($offset, $char),
        };
    }

    // Because YAML also breaks a line at a carriage return that no line feed follows.
    private function isABareCarriageReturn(int $offset): bool
    {
        return "\r" === $this->source[$offset] && "\n" !== ($this->source[$offset + 1] ?? '');
    }

    private function afterLineEnd(int $offset): int
    {
        if ($this->blockScalarFollows) {
            $this->blockScalarFollows = false;
            $offset = $this->afterBlockScalarBody($offset);
        }

        return $this->afterLineStart($offset);
    }

    // Because a block scalar holds every following line indented as deep as its explicit indentation, or else as its first line, which must be indented deeper than the scalar's parent node.
    private function afterBlockScalarBody(int $offset): int
    {
        $contentIndent = $this->blockScalarIndent;

        while ($offset < $this->length) {
            $indent = strspn($this->source, ' ', $offset);
            $lineEnd = $offset + strcspn($this->source, "\r\n", $offset);
            $text = $offset + $indent + strspn($this->source, " \t", $offset + $indent);

            if ($text < $lineEnd && $indent < ($contentIndent ?? $this->parentIndent + 1)) {
                return $offset;
            }

            $contentIndent ??= $text < $lineEnd ? $indent : null;
            $offset = $lineEnd + ("\r\n" === substr($this->source, $lineEnd, 2) ? 2 : 1);
        }

        return $this->length;
    }

    // Because a `#` right after other text, as in `a#b`, belongs to a plain scalar.
    private function followsABreak(int $offset): bool
    {
        return $this->lineStart === $offset || str_contains(self::BLANKS."\n", $this->source[$offset - 1]);
    }

    private function afterComment(int $offset): int
    {
        $end = $offset + strcspn($this->source, "\r\n", $offset);
        $this->comments[] = [$offset, substr($this->source, $offset, $end - $offset)];
        $this->plainScalarOpen = false;

        return $end;
    }

    // Because quotes, flow brackets and block scalar headers have their meaning only where a node starts, while inside a plain scalar they are its text.
    private function afterValueStart(int $offset, string $char): int
    {
        $this->nodeColumn = $this->propertyColumn ?? $offset - $this->lineStart;
        $this->propertyColumn = null;

        return match (true) {
            "'" === $char => $this->afterJsonNode($this->afterSingleQuoted($offset + 1)),
            '"' === $char => $this->afterJsonNode($this->afterDoubleQuoted($offset + 1)),
            '[' === $char, '{' === $char => $this->openFlow($offset + 1),
            '!' === $char, '&' === $char => $this->afterNodeProperty($offset + strcspn($this->source, self::BLANKS."\n", $offset)),
            str_contains('-?', $char) && $this->blankFollows($offset) => $this->afterEntryIndicator($offset + 1),
            ':' === $char && $this->blankFollows($offset) => $offset + 1,
            0 === $this->flowDepth && 1 === preg_match(self::BLOCK_SCALAR_HEADER, $this->source, $header, 0, $offset) => $this->afterBlockScalarHeader($offset + strlen($header[0]), $header[1]),
            default => $this->afterPlainCharacter($offset, $char),
        };
    }

    // Because an anchor or a tag belongs to the node after it on its line, as in `- &id key: |`, that node's key keeps the property's column.
    private function afterNodeProperty(int $end): int
    {
        $this->propertyColumn = $this->nodeColumn;

        return $end;
    }

    private function afterSingleQuoted(int $offset): int
    {
        while (false !== $close = strpos($this->source, "'", $offset)) {
            if ("'" !== ($this->source[$close + 1] ?? '')) {
                return $close + 1;
            }

            $offset = $close + 2;
        }

        return $this->length;
    }

    // Because a `:` may touch the value after a quoted scalar or a flow collection, as in `{"a":"b"}`.
    private function afterJsonNode(int $end): int
    {
        $this->jsonNodeEnded = true;
        $this->valueStarts = false;
        $this->plainScalarOpen = false;

        return $end;
    }

    private function afterDoubleQuoted(int $offset): int
    {
        while (($offset += strcspn($this->source, '"\\', $offset)) < $this->length) {
            if ('"' === $this->source[$offset]) {
                return $offset + 1;
            }

            $offset += 2;
        }

        return $this->length;
    }

    private function openFlow(int $end): int
    {
        ++$this->flowDepth;

        return $end;
    }

    private function blankFollows(int $offset): bool
    {
        return str_contains(self::BLANKS."\n", $this->source[$offset + 1] ?? "\n");
    }

    // Because the node of a `- ` or `? ` entry has the indicator's column as its parent's indentation, as `- |` shows.
    private function afterEntryIndicator(int $end): int
    {
        $this->parentIndent = $this->nodeColumn;

        return $end;
    }

    private function afterBlockScalarHeader(int $end, string $indicators): int
    {
        $this->blockScalarFollows = true;
        $explicit = (int) preg_replace('~\D~', '', $indicators);
        $this->blockScalarIndent = 0 < $explicit ? $this->parentIndent + $explicit : null;
        $this->valueStarts = false;
        $this->plainScalarOpen = false;

        return $end;
    }

    // Because in a flow collection a `:` ends a key before a blank or a flow indicator or right after a quoted key, while in a block mapping it needs a blank after it and makes its key the parent of the value.
    private function afterPlainCharacter(int $offset, string $char): int
    {
        return match (true) {
            ':' === $char && 0 < $this->flowDepth && ($this->jsonNodeEnded || str_contains(self::ENDS_A_FLOW_KEY, $this->source[$offset + 1] ?? ' ')) => $this->startingAValue($offset + 1),
            ':' === $char && 0 === $this->flowDepth && $this->blankFollows($offset) => $this->afterMappingIndicator($offset + 1),
            0 < $this->flowDepth && ',' === $char => $this->startingAValue($offset + 1),
            0 < $this->flowDepth && (']' === $char || '}' === $char) => $this->afterJsonNode($this->closedFlow($offset + 1)),
            default => $this->afterPlainRun($offset + max(1, strcspn($this->source, self::ENDS_A_PLAIN_RUN, $offset))),
        };
    }

    private function startingAValue(int $end): int
    {
        $this->valueStarts = true;
        $this->plainScalarOpen = false;
        $this->jsonNodeEnded = false;

        return $end;
    }

    private function afterMappingIndicator(int $end): int
    {
        $this->parentIndent = $this->nodeColumn;

        return $this->startingAValue($end);
    }

    private function closedFlow(int $end): int
    {
        --$this->flowDepth;

        return $end;
    }

    private function afterPlainRun(int $end): int
    {
        $this->valueStarts = false;
        $this->plainScalarOpen = true;
        $this->jsonNodeEnded = false;

        return $end;
    }
}
