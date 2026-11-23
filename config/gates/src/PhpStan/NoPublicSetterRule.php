<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<Stmt>
 */
final readonly class NoPublicSetterRule implements Rule
{
    private RepositoryCode $repositoryCode;

    private SetterMethod $setters;

    private AncestorDeclarations $ancestors;

    private VendorSources $vendorSources;

    public function __construct(string $root, private ReflectionProvider $reflections)
    {
        $this->repositoryCode = new RepositoryCode($root);
        $this->setters = new SetterMethod();
        $this->ancestors = new AncestorDeclarations();
        $this->vendorSources = new VendorSources($root);
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
            $node instanceof Trait_ => $this->settersByAliasInTrait($node),
            $node instanceof TraitUse => $this->settersByAliasInClass($node, $scope),
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

        // a trait's method is visited here once per using class, so NoPublicTraitSetterRule judges it once from the collected uses instead
        if (!$class instanceof ClassReflection || $scope->isInTrait() || !$this->setters->isPublicSetter($method) || $this->vendorDeclares($class, $name)) {
            return [];
        }

        return [$this->setters->finding($class->getDisplayName(), $name)->line($method->getStartLine())->build()];
    }

    // PHPStan visits a trait's own `use` only inside a class that uses the trait, so an alias written in a trait is judged where the trait is declared, which covers a trait no class uses and reports a used one once
    /**
     * @return list<IdentifierRuleError>
     */
    private function settersByAliasInTrait(Trait_ $trait): array
    {
        $name = (string) $trait->namespacedName;

        if (!$this->reflections->hasClass($name)) {
            return [];
        }

        $declared = $this->reflections->getClass($name);
        $findings = [];

        foreach ($trait->getTraitUses() as $use) {
            foreach ($this->publicSetterAliases($use, $declared) as [$alias, $setter]) {
                $findings[] = $this->setters->finding($name, $setter)->line($alias->getStartLine())->build();
            }
        }

        return $findings;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function settersByAliasInClass(TraitUse $use, Scope $scope): array
    {
        $class = $scope->getClassReflection();

        if (!$class instanceof ClassReflection || $scope->isInTrait()) {
            return [];
        }

        $aliases = array_filter($this->publicSetterAliases($use, $class), fn (array $alias): bool => !$this->vendorDeclares($class, $alias[1]));

        return array_values(array_map(fn (array $alias): IdentifierRuleError => $this->setters->finding($class->getDisplayName(), $alias[1])->line($alias[0]->getStartLine())->build(), $aliases));
    }

    /**
     * @return list<array{Alias, string}>
     */
    private function publicSetterAliases(TraitUse $use, ClassReflection $owner): array
    {
        $found = [];

        foreach ($use->adaptations as $adaptation) {
            $setter = $adaptation instanceof Alias ? $this->publicSetterOfAlias($adaptation, $use, $owner) : null;

            if (null !== $setter) {
                $found[] = [$adaptation, $setter];
            }
        }

        return $found;
    }

    // PHP matches method names in any letter case, so the alias is judged by the name the owner really exposes, not by the spelling in the `as` clause
    private function publicSetterOfAlias(Alias $alias, TraitUse $use, ClassReflection $owner): ?string
    {
        $name = $this->aliasedName($alias);
        $native = $owner->getNativeReflection();

        // an alias that keeps the trait's name and visibility adds nothing the trait's own declaration did not show
        if ((!$alias->newName instanceof Identifier && !$this->widensToPublic($alias, $use)) || !$native->hasMethod($name)) {
            return null;
        }

        // a method the owner declares itself wins over the alias and is judged as a method, so the alias only counts when it is what callers really get
        if (array_key_exists(strtolower($name), array_change_key_case($native->getBetterReflection()->getImmediateMethods()))) {
            return null;
        }

        $method = $native->getMethod($name);

        return $method->isPublic() && $this->setters->isSetterName($method->getName()) ? $method->getName() : null;
    }

    private function aliasedName(Alias $alias): string
    {
        return ($alias->newName ?? $alias->method)->toString();
    }

    // `setTitle as public` on a method the trait already declares public changes nothing, so the trait's declaration stays its only finding
    private function widensToPublic(Alias $alias, TraitUse $use): bool
    {
        if (Modifiers::PUBLIC !== (($alias->newModifier ?? 0) & Modifiers::VISIBILITY_MASK)) {
            return false;
        }

        $method = $alias->method->toString();
        $traits = array_filter(array_map(fn (Name $trait): ?ClassReflection => $this->reflections->hasClass($trait->toString()) ? $this->reflections->getClass($trait->toString()) : null, $alias->trait instanceof Name ? [$alias->trait] : $use->traits));
        $declaring = array_find($traits, static fn (ClassReflection $trait): bool => $trait->getNativeReflection()->hasMethod($method));

        return !$declaring instanceof ClassReflection || !$declaring->getNativeReflection()->getMethod($method)->isPublic();
    }

    // PHP does not let an implementation rename or hide a method of a vendor interface or parent, so only a setter the repository declares first can be avoided
    private function vendorDeclares(ClassReflection $class, string $name): bool
    {
        return array_any($this->ancestors->filesDeclaring($class, $name), $this->vendorSources->contain(...));
    }
}
