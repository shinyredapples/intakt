<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;

final readonly class TraitMethodDeclaration
{
    // because a class may expose a trait method under another name, and two traits may declare their methods on the same lines of one file, only the declaring trait together with the place tells two declarations apart
    public static function isSame(ReflectionMethod $bound, ReflectionMethod $declared): bool
    {
        $left = $bound->getBetterReflection();
        $right = $declared->getBetterReflection();

        return 0 === strcasecmp($left->getDeclaringClass()->getName(), $right->getDeclaringClass()->getName())
            && $left->getFileName() === $right->getFileName()
            && $left->getStartLine() === $right->getStartLine()
            && $left->getEndLine() === $right->getEndLine();
    }
}
