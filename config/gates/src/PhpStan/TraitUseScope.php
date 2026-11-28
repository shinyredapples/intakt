<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node\Stmt\TraitUse;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\Reflection\ClassReflection;

final readonly class TraitUseScope
{
    public function __construct(
        public TraitUse $node,
        public ClassReflection $class,
        public ClassReflection $declarer,
    ) {
    }

    // because only the class's own use block has the last word on the visibility of the names it takes, while the class can still hide a name a wrapper trait's block made public
    public function keepsPublic(ReflectionMethod $bound): bool
    {
        return $this->declarer === $this->class || $bound->isPublic();
    }
}
