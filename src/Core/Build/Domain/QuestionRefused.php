<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use RuntimeException;

final class QuestionRefused extends RuntimeException
{
    public static function notJson(): self
    {
        return new self('the question is not a JSON object');
    }

    public static function emptyQuestion(): self
    {
        return new self('the question text is empty');
    }

    public static function noChoices(): self
    {
        return new self('the question has no list of choices');
    }

    public static function emptyChoice(): self
    {
        return new self('a choice is not a non-empty text');
    }

    public static function recommendationNotAChoice(): self
    {
        return new self('the recommendation is not one of the choices');
    }

    public static function tooLong(int $limit): self
    {
        return new self(sprintf('the question is longer than %d bytes', $limit));
    }
}
