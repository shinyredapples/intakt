<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeUtils;

final readonly class ClassNames
{
    /**
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    public function namedAmong(Name|Expr|Class_ $class, array $candidates, Scope $scope): array
    {
        return $this->matching($this->namesOf($class, $scope), $candidates);
    }

    /**
     * @return list<string>
     */
    public function namesOf(Name|Expr|Class_ $class, Scope $scope): array
    {
        return match (true) {
            $class instanceof Name => [$scope->resolveName($class)],
            $class instanceof Expr => $this->ofType($scope->getType($class)),
            default => [],
        };
    }

    // null when a part of the class expression's type names no class, such as a plain `string` or `object`, so the class is known only at run time
    /**
     * @return list<string>|null
     */
    public function fullyNamed(Name|Expr|Class_ $class, Scope $scope): ?array
    {
        if (!$class instanceof Expr) {
            return $class instanceof Name ? [$scope->resolveName($class)] : [];
        }

        $parts = array_map($this->ofType(...), TypeUtils::flattenTypes($scope->getType($class)));

        return array_any($parts, static fn (array $names): bool => [] === $names) ? null : array_values(array_unique(array_merge(...$parts)));
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

    // because a `class-string<HasPlace>` may name any class that implements HasPlace, but a `class-string<HasPlace&Countable>` only one that implements both, a candidate counts as named when it is, or extends or implements, every class of one member of the expression's union
    /**
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    public function standingFor(Name|Expr|Class_ $class, array $candidates, Scope $scope): array
    {
        $members = match (true) {
            $class instanceof Name => [[$scope->resolveName($class)]],
            $class instanceof Expr => $this->unionMembersOf($scope->getType($class)),
            default => [],
        };

        return array_values(array_filter($candidates, static fn (string $candidate): bool => array_any($members, static fn (array $classes): bool => [] !== $classes && array_all($classes, static fn (string $name): bool => 0 === strcasecmp($name, $candidate) || new ObjectType($name)->isSuperTypeOf(new ObjectType($candidate))->yes()))));
    }

    // because an intersection such as `HasPlace&Countable` is one member whose classes a candidate must all satisfy, while each member of a union is judged on its own
    /**
     * @return list<list<string>>
     */
    private function unionMembersOf(Type $type): array
    {
        $members = [];

        foreach (TypeUtils::flattenTypes($type) as $member) {
            foreach (TypeUtils::flattenTypes($member->getObjectTypeOrClassStringObjectType()) as $object) {
                $members[] = $object->getObjectClassNames();
            }

            foreach ($member->getConstantStrings() as $name) {
                $members[] = [$this->withoutLeadingBackslash($name->getValue())];
            }
        }

        return $members;
    }

    // because PHP accepts at most one leading backslash in a class string
    public function withoutLeadingBackslash(string $name): string
    {
        return str_starts_with($name, '\\') ? substr($name, 1) : $name;
    }
}
