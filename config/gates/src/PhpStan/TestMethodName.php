<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

final readonly class TestMethodName
{
    // an acronym or a capital with the lowercase letters and digits after it is one word, so HTTPRequest and Its192Px are two words each; a run of capitals stays one word even when it starts with A or I, because only a dictionary could tell an article in AURL from the acronym ASCII
    private const string WORD = '[A-Z]{2,}(?![a-z])[0-9]*|[A-Z][a-z0-9]*';

    private const int SENTENCE_WORDS = 3;

    public static function readsAsSentence(string $name): bool
    {
        return str_starts_with($name, 'test') && self::isSentence(substr($name, 4));
    }

    private static function isSentence(string $words): bool
    {
        return 1 === preg_match('~^(?:'.self::WORD.')+$~', $words) && self::SENTENCE_WORDS <= preg_match_all('~'.self::WORD.'~', $words);
    }
}
