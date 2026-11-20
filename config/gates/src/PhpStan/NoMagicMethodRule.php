<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

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

        return array_values(array_map(fn (ClassMethod $method): IdentifierRuleError => $this->magicMethod((string) $trait->namespacedName, $method->name->toString(), $method->getStartLine()), $magic));
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function magicMethodsByTraitAlias(TraitUse $use, Scope $scope): array
    {
        $class = $scope->getClassReflection();

        if (!$class instanceof ClassReflection) {
            return [];
        }

        $aliases = array_filter($use->adaptations, fn (Node $adaptation): bool => $adaptation instanceof Alias && null !== $adaptation->newName && $this->isMagic($adaptation->newName->toString()));

        return array_values(array_map(fn (Alias $alias): IdentifierRuleError => $this->magicMethod($class->getDisplayName(), (string) $alias->newName, $alias->getStartLine()), $aliases));
    }

    private function isMagic(string $name): bool
    {
        $lowercase = strtolower($name);

        return str_starts_with($lowercase, '__') && !in_array($lowercase, self::ALLOWED, true);
    }

    private function magicMethod(string $class, string $method, int $line): IdentifierRuleError
    {
        $message = sprintf('Method %s::%s() is a magic method: no magic methods other than __construct() and __invoke() (and __toString() of Stringable), because PHP calls them implicitly, so what they do is invisible to static analysis, the IDE and readers; declare the property or the method, or give the class a named method for the lookup.', $class, $method);

        return RuleErrorBuilder::message($message)->identifier('intakt.noMagicMethod')->line($line)->build();
    }
}
