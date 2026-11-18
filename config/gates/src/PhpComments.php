<?php

declare(strict_types=1);

namespace Intakt\Gates;

use PhpToken;

final readonly class PhpComments implements CommentLanguage
{
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
