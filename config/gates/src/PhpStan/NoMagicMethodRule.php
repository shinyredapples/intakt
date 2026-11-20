<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
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
final readonly class NoMagicMethodRule implements Rule
{
    // PHP reserves every method name starting with "__" for magic methods and matches method names case-insensitively, so the exemptions are lowercase; __toString is PHP's own Stringable interface, which static analysis understands
    private const array ALLOWED = ['__construct', '__invoke', '__tostring'];

    private RepositoryCode $repositoryCode;

    public function __construct(string $root)
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
            $node instanceof ClassMethod => $this->magicMethodOfClass($node, $scope),
            $node instanceof Trait_ => $this->magicMethodsOfTrait($node),
            $node instanceof TraitUse => $this->magicMethodsByTraitAlias($node, $scope),
            default => [],
        };

        return $this->repositoryCode->contains($scope->getFile()) ? $findings : [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function magicMethodOfClass(ClassMethod $method, Scope $scope): array
    {
        $class = $scope->getClassReflection();

        // PHPStan analyzes a trait's methods only inside each class that uses it, so they are judged once where the trait declares them, which also covers a trait no class uses
        if (!$class instanceof ClassReflection || $scope->isInTrait() || !$this->isMagic($method->name->toString())) {
            return [];
        }

        return [$this->magicMethod($class->getDisplayName(), $method->name->toString(), $method->getStartLine())];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function magicMethodsOfTrait(Trait_ $trait): array
    {
        $magic = array_filter($trait->getMethods(), fn (ClassMethod $method): bool => $this->isMagic($method->name->toString()));
        $findings = array_map(fn (ClassMethod $method): IdentifierRuleError => $this->magicMethod((string) $trait->namespacedName, $method->name->toString(), $method->getStartLine()), $magic);

        // PHPStan visits a trait's own `use` only inside a class that uses the trait, so an alias written in a trait is judged here too, which covers a trait no class uses and reports a used one once
        foreach ($trait->getTraitUses() as $use) {
            foreach ($this->magicAliases($use) as $alias) {
                $findings[] = $this->magicMethod((string) $trait->namespacedName, (string) $alias->newName, $alias->getStartLine());
            }
        }

        return array_values($findings);
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function magicMethodsByTraitAlias(TraitUse $use, Scope $scope): array
    {
        $class = $scope->getClassReflection();

        if (!$class instanceof ClassReflection || $scope->isInTrait()) {
            return [];
        }

        return array_map(fn (Alias $alias): IdentifierRuleError => $this->magicMethod($class->getDisplayName(), (string) $alias->newName, $alias->getStartLine()), $this->magicAliases($use));
    }

    /**
     * @return list<Alias>
     */
    private function magicAliases(TraitUse $use): array
    {
        return array_values(array_filter($use->adaptations, fn (Node $adaptation): bool => $adaptation instanceof Alias && $adaptation->newName instanceof Identifier && $this->isMagic($adaptation->newName->toString())));
    }

    private function isMagic(string $name): bool
    {
        $lowercase = strtolower($name);

        return str_starts_with($lowercase, '__') && !in_array($lowercase, self::ALLOWED, true);
    }

    private function magicMethod(string $class, string $method, int $line): IdentifierRuleError
    {
        $message = sprintf('Method %s::%s() is a magic method: no magic methods other than __construct(), __invoke() and __toString(), because PHP calls them implicitly, so what they do is invisible to static analysis, the IDE and readers; declare the property or the method, or give the class a named method for the lookup.', $class, $method);

        return RuleErrorBuilder::message($message)->identifier('intakt.noMagicMethod')->line($line)->build();
    }
}
