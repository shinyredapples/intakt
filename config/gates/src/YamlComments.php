<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class YamlComments implements CommentLanguage
{
    // Because a YAML comment keeps its `#`, as a PHP `#` comment does, the hashes and the white space around them are syntax.
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
        return new YamlLexer($source)->comments();
    }
}
