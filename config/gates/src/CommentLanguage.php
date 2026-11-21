<?php

declare(strict_types=1);

namespace Intakt\Gates;

interface CommentLanguage
{
    /**
     * @return list<array{int, string}>
     */
    public function commentsIn(string $source): array;

    // Because only the language knows which leading characters of a comment line are its syntax and which are the comment's text.
    public function lineSyntax(): string;

    // Because skips and not-implemented throws are PHP code, only a file that holds PHP code gets the code scan.
    public function holdsPhpCode(): bool;
}
