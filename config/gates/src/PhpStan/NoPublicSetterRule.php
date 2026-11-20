<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Modifiers;
use PhpParser\Node;
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

        // PHPStan analyzes a trait's methods only inside each class that uses it, so they are judged once where the trait declares them, which also covers a trait no class uses
        if (!$class instanceof ClassReflection || $scope->isInTrait() || !$this->isPublicSetter($method) || $this->vendorDeclares($class, $name)) {
            return [];
        }

        return [$this->publicSetter($class->getDisplayName(), $name, $method->getStartLine())];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function settersOfTrait(Trait_ $trait): array
    {
        $setters = array_filter($trait->getMethods(), $this->isPublicSetter(...));

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

        if (null === $alias->newName && Modifiers::PUBLIC !== $alias->newModifier) {
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

        return $this->isVendorFile($prototype->getDeclaringClass()->getFileName());
    }

    // PHP's own classes have no file; any file of the repository outside vendor/ (migrations/ too) is code the repository could rename
    private function isVendorFile(false|string $file): bool
    {
        return false === $file || !str_starts_with($file, $this->root.'/') || str_starts_with($file, $this->root.'/vendor/');
    }

    private function publicSetter(string $class, string $method, int $line): IdentifierRuleError
    {
        $message = sprintf('Method %s::%s() is a public setter: no public setters, because an object changed after construction can be seen half set up and changed by any caller; pass the value to the constructor, or return a new instance from a with*() method (clone() with overrides).', $class, $method);

        return RuleErrorBuilder::message($message)->identifier('intakt.noPublicSetter')->line($line)->build();
    }
}
