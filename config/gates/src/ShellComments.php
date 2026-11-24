<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class ShellComments implements CommentLanguage
{
    // Because a shell comment keeps its `#`, as a PHP comment token does, the white space and the leading hashes are syntax.
    private const string LINE_SYNTAX = '~\G(?:\s++|#++)~';

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
        return new ShellLexer($source)->comments();
    }
}
