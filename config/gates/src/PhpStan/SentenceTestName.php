<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Reflection\ClassReflection;

final readonly class SentenceTestName
{
    // an acronym or a capital with the lowercase letters and digits after it is one word, so HTTPRequest and Its192Px are two words each; a run of capitals stays one word even when it starts with A or I, because only a dictionary could tell an article in AURL from the acronym ASCII
    private const string WORD = '[A-Z]{2,}(?![a-z])[0-9]*|[A-Z][a-z0-9]*';

    private const int SENTENCE_WORDS = 3;

    public function isMisnamedTest(ReflectionMethod $method): bool
    {
        $name = $method->getName();

        return PhpUnitTestMethod::isRun($method) && (!str_starts_with($name, 'test') || !$this->readsAsSentence(substr($name, 4)));
    }

    // because PHPUnit calls only a static provider and iterates what it returns
    public function mayBeProvider(ClassReflection $class, ReflectionMethod $method): bool
    {
        return $method->isStatic() && !PhpUnitTestMethod::isMarked($method) && !$class->getNativeMethod($method->getName())->getOnlyVariant()->getNativeReturnType()->isIterable()->no();
    }

    private function readsAsSentence(string $words): bool
    {
        return 1 === preg_match('~^(?:'.self::WORD.')+$~', $words) && self::SENTENCE_WORDS <= preg_match_all('~'.self::WORD.'~', $words);
    }
}
