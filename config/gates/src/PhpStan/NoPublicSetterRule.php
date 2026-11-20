<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Node>
 */
final readonly class NoPublicSetterRule implements Rule
{
    private RepositoryCode $repositoryCode;

    private SetterMethod $setters;

    private AncestorDeclarations $ancestors;

    public function __construct(private string $root, private ReflectionProvider $reflections)
    {
        $this->repositoryCode = new RepositoryCode($root);
        $this->setters = new SetterMethod();
        $this->ancestors = new AncestorDeclarations();
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof CollectedDataNode) {
            return $this->settersOfTraits($node);
        }

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

        // a trait's method is visited here once per using class, so it is judged once from the collected uses instead
        if (!$class instanceof ClassReflection || $scope->isInTrait() || !$this->setters->isPublicSetter($method) || $this->vendorDeclares($class, $name)) {
            return [];
        }

        return [$this->publicSetter($class->getDisplayName(), $name)->line($method->getStartLine())->build()];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function settersOfTraits(CollectedDataNode $collected): array
    {
        $uses = array_merge(...array_values($collected->get(TraitSetterUseCollector::class)));
        $findings = [];

        foreach ($collected->get(TraitSetterDeclarationCollector::class) as $file => $traits) {
            foreach ($this->repositoryCode->contains($file) ? array_merge(...$traits) : [] as [$trait, $method, $line]) {
                if (!$this->vendorRequiresInEveryUse($trait, $method, $uses)) {
                    $findings[] = $this->publicSetter($trait, $method)->file($file)->line($line)->build();
                }
            }
        }

        return $findings;
    }

    // a trait no analyzed class uses has no vendor ancestor that could require the name, and one class without such an ancestor is enough to make the name the repository's choice
    /**
     * @param list<array{string, string, list<string>}> $uses
     */
    private function vendorRequiresInEveryUse(string $trait, string $method, array $uses): bool
    {
        $ofMethod = array_filter($uses, static fn (array $use): bool => $trait === $use[0] && $method === $use[1]);

        return [] !== $ofMethod && array_all($ofMethod, fn (array $use): bool => array_any($use[2], $this->isVendorFile(...)));
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
            foreach ($this->publicSetterAliases($use, $declared) as $alias) {
                $findings[] = $this->publicSetter($name, $this->aliasedName($alias))->line($alias->getStartLine())->build();
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

        $aliases = array_filter($this->publicSetterAliases($use, $class), fn (Alias $alias): bool => !$this->vendorDeclares($class, $this->aliasedName($alias)));

        return array_values(array_map(fn (Alias $alias): IdentifierRuleError => $this->publicSetter($class->getDisplayName(), $this->aliasedName($alias))->line($alias->getStartLine())->build(), $aliases));
    }

    /**
     * @return list<Alias>
     */
    private function publicSetterAliases(TraitUse $use, ClassReflection $owner): array
    {
        return array_values(array_filter($use->adaptations, fn (Node $adaptation): bool => $adaptation instanceof Alias && $this->aliasesToPublicSetter($adaptation, $owner)));
    }

    private function aliasesToPublicSetter(Alias $alias, ClassReflection $owner): bool
    {
        $name = $this->aliasedName($alias);
        $visibility = ($alias->newModifier ?? 0) & Modifiers::VISIBILITY_MASK;

        // an alias that keeps the trait's name and visibility adds nothing the trait's own declaration did not show
        if (!$this->setters->isSetterName($name) || (!$alias->newName instanceof Identifier && Modifiers::PUBLIC !== $visibility)) {
            return false;
        }

        if (0 !== $visibility) {
            return Modifiers::PUBLIC === $visibility;
        }

        $native = $owner->getNativeReflection();

        return $native->hasMethod($name) && $native->getMethod($name)->isPublic();
    }

    private function aliasedName(Alias $alias): string
    {
        return ($alias->newName ?? $alias->method)->toString();
    }

    // PHP does not let an implementation rename or hide a method of a vendor interface or parent, so only a setter the repository declares first can be avoided
    private function vendorDeclares(ClassReflection $class, string $name): bool
    {
        return array_any($this->ancestors->filesDeclaring($class, $name), $this->isVendorFile(...));
    }

    // a file of the repository outside vendor/ (migrations/ too) counts as the repository's, because the repository could rename what it declares
    private function isVendorFile(string $file): bool
    {
        return AncestorDeclarations::BUILT_IN === $file || !str_starts_with($file, $this->root.'/') || str_starts_with($file, $this->root.'/vendor/');
    }

    /**
     * @return RuleErrorBuilder<IdentifierRuleError>
     */
    private function publicSetter(string $class, string $method): RuleErrorBuilder
    {
        $message = sprintf('Method %s::%s() is a public setter: no public setters, because an object changed after construction can be seen half set up and changed by any caller; pass the value to the constructor, or return a new instance from a with*() method (clone() with overrides).', $class, $method);

        return RuleErrorBuilder::message($message)->identifier('intakt.noPublicSetter');
    }
}
