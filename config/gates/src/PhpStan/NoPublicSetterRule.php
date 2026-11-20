<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Stmt>
 */
final readonly class NoPublicSetterRule implements Rule
{
    // "set" in any letter case, since PHP matches method names case-insensitively, not followed by a lowercase letter, so setName(), SetName() and set_name() are setters while settle(), setup() and Settings() are words of their own
    private const string SETTER = '~^[sS][eE][tT](?![a-z])~';

    private RepositoryCode $repositoryCode;

    public function __construct(private string $root)
    {
        $this->repositoryCode = new RepositoryCode($root);
    }

    public function getNodeType(): string
    {
        return Stmt::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $findings = match (true) {
            $node instanceof ClassMethod => $this->setterOfClass($node, $scope),
            $node instanceof Trait_ => $this->settersOfTrait($node),
            $node instanceof TraitUse => $this->settersByTraitAlias($node, $scope),
            default => [],
        };

        return $this->repositoryCode->contains($scope->getFile()) ? $findings : [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function setterOfClass(ClassMethod $method, Scope $scope): array
    {
        $class = $scope->getClassReflection();
        $name = $method->name->toString();

        if (!$class instanceof ClassReflection || !$this->isPublicSetter($method) || $this->vendorDeclares($class, $name)) {
            return [];
        }

        if (!$scope->isInTrait()) {
            return [$this->publicSetter($class->getDisplayName(), $name, $method->getStartLine())];
        }

        // judged here only when it overrides, because only then can a vendor ancestor of the using class require it; any other trait setter is reported once, where the trait declares it
        return $this->overrides($method) ? [$this->publicSetter($scope->getTraitReflection()->getDisplayName(), $name, $method->getStartLine())] : [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function settersOfTrait(Trait_ $trait): array
    {
        // PHPStan analyzes a trait's methods only inside each class that uses it, so a trait no class uses would go unjudged; a trait setter marked #[Override] (PHP checks that in each using class) is judged there instead, against that class's vendor ancestors
        $setters = array_filter($trait->getMethods(), fn (ClassMethod $method): bool => $this->isPublicSetter($method) && !$this->overrides($method));

        return array_values(array_map(fn (ClassMethod $method): IdentifierRuleError => $this->publicSetter((string) $trait->namespacedName, $method->name->toString(), $method->getStartLine()), $setters));
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function settersByTraitAlias(TraitUse $use, Scope $scope): array
    {
        $class = $scope->getClassReflection();

        if (!$class instanceof ClassReflection) {
            return [];
        }

        $aliases = array_filter($use->adaptations, fn (Node $adaptation): bool => $adaptation instanceof Alias && $this->aliasesToPublicSetter($adaptation, $class));

        return array_values(array_map(fn (Alias $alias): IdentifierRuleError => $this->publicSetter($class->getDisplayName(), $this->aliasedName($alias), $alias->getStartLine()), $aliases));
    }

    // an alias that keeps the trait's name and visibility adds nothing the trait's own declaration did not show
    private function aliasesToPublicSetter(Alias $alias, ClassReflection $class): bool
    {
        $name = $this->aliasedName($alias);

        if (!$alias->newName instanceof Identifier && Modifiers::PUBLIC !== $alias->newModifier) {
            return false;
        }

        $native = $class->getNativeReflection();

        return 1 === preg_match(self::SETTER, $name) && $native->hasMethod($name) && $native->getMethod($name)->isPublic() && !$this->vendorDeclares($class, $name);
    }

    private function aliasedName(Alias $alias): string
    {
        return ($alias->newName ?? $alias->method)->toString();
    }

    private function isPublicSetter(ClassMethod $method): bool
    {
        return $method->isPublic() && 1 === preg_match(self::SETTER, $method->name->toString());
    }

    // PHP does not let an implementation rename or hide a method of a vendor interface or parent, so only a setter the repository declares first can be avoided
    private function vendorDeclares(ClassReflection $class, string $name): bool
    {
        return array_any([...$class->getInterfaces(), ...$class->getParents()], fn (ClassReflection $ancestor): bool => $this->isVendorPrototype($ancestor, $name));
    }

    private function isVendorPrototype(ClassReflection $ancestor, string $name): bool
    {
        $native = $ancestor->getNativeReflection();

        if (!$native->hasMethod($name)) {
            return false;
        }

        $prototype = $native->getMethod($name);

        // a child's method of the same name does not override a private one, so its name is the author's choice
        if ($prototype->isPrivate()) {
            return false;
        }

        $file = $prototype->getDeclaringClass()->getFileName();

        // a class without a file is PHP's own, and a file of the repository outside vendor/ (migrations/ too) counts as the repository's, because the repository could rename it
        return false === $file || $this->isOutsideRepositorySources($file);
    }

    private function isOutsideRepositorySources(string $file): bool
    {
        return !str_starts_with($file, $this->root.'/') || str_starts_with($file, $this->root.'/vendor/');
    }

    private function overrides(ClassMethod $method): bool
    {
        return array_any($method->attrGroups, static fn (AttributeGroup $group): bool => array_any($group->attrs, static fn (Attribute $attribute): bool => 'override' === $attribute->name->toLowerString()));
    }

    private function publicSetter(string $class, string $method, int $line): IdentifierRuleError
    {
        $message = sprintf('Method %s::%s() is a public setter: no public setters, because an object changed after construction can be seen half set up and changed by any caller; pass the value to the constructor, or return a new instance from a with*() method (clone() with overrides).', $class, $method);

        return RuleErrorBuilder::message($message)->identifier('intakt.noPublicSetter')->line($line)->build();
    }
}
