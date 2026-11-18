<?php

declare(strict_types=1);

namespace Intakt\Gates;

interface CommentLanguage
{
    /**
     * @return list<array{int, string}>
     */
    public function commentsIn(string $source): array;
}
