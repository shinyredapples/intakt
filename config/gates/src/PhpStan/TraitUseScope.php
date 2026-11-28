<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node\Stmt\TraitUse;
use PHPStan\Reflection\ClassReflection;

final readonly class TraitUseScope
{
    public function __construct(
        public TraitUse $node,
        public ClassReflection $class,
        public ClassReflection $declarer,
    ) {
    }

    // because only the class's own use block has the last word on the visibility of the names it takes, while a wrapper trait's block can be overruled by the class
    public function isInClass(): bool
    {
        return $this->declarer === $this->class;
    }
}
