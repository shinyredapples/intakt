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

    // because a `class-string<HasPlace>` may name any class that implements HasPlace, but a `class-string<HasPlace&Countable>` only one that satisfies every part, a candidate counts as named unless every member of the expression's union rules it out
    /**
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    public function standingFor(Name|Expr|Class_ $class, array $candidates, Scope $scope): array
    {
        if (!$class instanceof Expr) {
            return array_values(array_filter($candidates, fn (string $candidate): bool => array_any($this->namesOf($class, $scope), fn (string $name): bool => $this->isOrExtends($candidate, $name))));
        }

        $members = TypeUtils::flattenTypes($scope->getType($class));

        return array_values(array_filter($candidates, fn (string $candidate): bool => array_any($members, fn (Type $member): bool => $this->mayName($member, $candidate))));
    }

    // because PHP accepts at most one leading backslash in a class string
    public function withoutLeadingBackslash(string $name): string
    {
        return str_starts_with($name, '\\') ? substr($name, 1) : $name;
    }

    private function isOrExtends(string $candidate, string $name): bool
    {
        return 0 === strcasecmp($name, $candidate) || new ObjectType($name)->isSuperTypeOf(new ObjectType($candidate))->yes();
    }

    // because an intersection such as `HasPlace&object{label: string}` is one type whose every part a candidate must satisfy, the whole type is asked and "maybe" counts, so an undecided bound is refused; a name string is compared as a class name, since the type of an unknown class answers "maybe" for every candidate
    private function mayName(Type $member, string $candidate): bool
    {
        $strings = $member->getConstantStrings();

        if ([] !== $strings) {
            return array_any($strings, fn (ConstantStringType $string): bool => $this->isOrExtends($candidate, $this->withoutLeadingBackslash($string->getValue())));
        }

        return array_any(TypeUtils::flattenTypes($member->getObjectTypeOrClassStringObjectType()), static fn (Type $object): bool => [] !== $object->getObjectClassNames() && !$object->isSuperTypeOf(new ObjectType($candidate))->no());
    }
}
