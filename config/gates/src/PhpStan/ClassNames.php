<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Type;

final readonly class ClassNames
{
    /**
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    public function namedAmong(Name|Expr|Class_ $class, array $candidates, Scope $scope): array
    {
        $names = match (true) {
            $class instanceof Name => [$scope->resolveName($class)],
            $class instanceof Expr => $this->ofType($scope->getType($class)),
            default => [],
        };

        return $this->matching($names, $candidates);
    }

    // because `$message::ofUser()` names the class through an object and `new $class()` through a class-name string, constant or typed `class-string<...>`
    /**
     * @return list<string>
     */
    public function ofType(Type $type): array
    {
        return [
            ...$type->getObjectTypeOrClassStringObjectType()->getObjectClassNames(),
            ...array_map(fn (ConstantStringType $name): string => $this->withoutLeadingBackslash($name->getValue()), $type->getConstantStrings()),
        ];
    }

    /**
     * @param list<string> $names
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    public function matching(array $names, array $candidates): array
    {
        return array_values(array_filter($candidates, static fn (string $candidate): bool => array_any($names, static fn (string $name): bool => 0 === strcasecmp($name, $candidate))));
    }

    // because PHP accepts at most one leading backslash in a class string
    public function withoutLeadingBackslash(string $name): string
    {
        return str_starts_with($name, '\\') ? substr($name, 1) : $name;
    }
}
