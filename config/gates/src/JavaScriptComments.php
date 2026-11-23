<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class JavaScriptComments implements CommentLanguage
{
    // Because JavaScript counts the no-break space, the byte order mark and every Unicode space separator as white space, written as bytes so that a file that is not valid UTF-8 still reads.
    private const string WHITE_SPACE = '(?:\s|\xC2\xA0|\xE1\x9A\x80|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80|\xEF\xBB\xBF)';

    // Because a JavaScript comment keeps its delimiters, as a PHP comment token does, its `//`, `/*` and leading stars are syntax, while a `#` is no JavaScript comment and stays the comment's text.
    private const string LINE_SYNTAX = '~\G(?:'.self::WHITE_SPACE.'++|//++|/\*++|\*++(?!/))~';

    public function lineSyntax(): string
    {
        return self::LINE_SYNTAX;
    }

    public function holdsPhpCode(): bool
    {
        return false;
    }

    public function commentsIn(string $source): array
    {
        return new JavaScriptLexer($source)->comments();
    }
}
