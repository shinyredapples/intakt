<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class JavaScriptComments implements CommentLanguage
{
    // Because a JavaScript comment keeps its delimiters, as a PHP comment token does, its `//`, `/*` and leading stars are syntax, while a `#` is no JavaScript comment and stays the comment's text.
    private const string LINE_SYNTAX = '~\G(?://++|/\*++|\*++(?!/))\s*+~';

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
