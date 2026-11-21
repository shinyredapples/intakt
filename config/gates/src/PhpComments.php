<?php

declare(strict_types=1);

namespace Intakt\Gates;

use PhpToken;

final readonly class PhpComments implements CommentLanguage
{
    private const string LINE_SYNTAX = '~\G(?://++|#++|/\*++|\*++(?!/))\s*+~';

    public function lineSyntax(): string
    {
        return self::LINE_SYNTAX;
    }

    public function holdsPhpCode(): bool
    {
        return true;
    }

    public function commentsIn(string $source): array
    {
        $comments = [];

        foreach (PhpToken::tokenize($source) as $token) {
            if ($token->is([T_COMMENT, T_DOC_COMMENT])) {
                $comments[] = [$token->pos, $token->text];
            }
        }

        return $comments;
    }
}
