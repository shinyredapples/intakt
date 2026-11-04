<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Analyser\Scope;

final readonly class DocblockLocation
{
    private function __construct(
        public string $file,
        public ?string $class,
        public ?string $trait,
        public ?string $function,
    ) {
    }

    public static function of(Node $node, Scope $scope): self
    {
        // because the scope at a function declaration still names no function, while the analyser files its docblock under the function it declares
        $function = $node instanceof Function_ ? $node->namespacedName?->toString() ?? $node->name->toString() : $scope->getFunctionName();

        return new self($scope->getFile(), $scope->getClassReflection()?->getName(), $scope->getTraitReflection()?->getName(), $function);
    }

    public static function inTrait(string $file, Trait_ $trait, ?string $method): self
    {
        // because the analyser files a trait's own docblocks under the trait's name in the class slot, as it does for a class
        return new self($file, $trait->namespacedName?->toString() ?? (string) $trait->name, null, $method);
    }
}
