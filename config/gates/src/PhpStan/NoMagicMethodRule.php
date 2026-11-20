<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<ClassMethod>
 */
final readonly class NoMagicMethodRule implements Rule
{
    // the methods that answer a property or method the class does not declare; PHP matches method names case-insensitively, so the list is lowercase
    private const array MAGIC = ['__get', '__set', '__isset', '__unset', '__call', '__callstatic'];

    private RepositoryCode $repositoryCode;

    public function __construct(string $root)
    {
        $this->repositoryCode = new RepositoryCode($root);
    }

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $scope->getClassReflection();

        if (!$class instanceof ClassReflection || !in_array($node->name->toLowerString(), self::MAGIC, true) || !$this->repositoryCode->contains($scope->getFile())) {
            return [];
        }

        return [$this->magicMethod($class->getDisplayName(), $node->name->toString())];
    }

    private function magicMethod(string $class, string $method): IdentifierRuleError
    {
        $message = sprintf('Method %s::%s() is a magic method: no magic methods, because a member that exists only at run time is invisible to static analysis, the IDE and readers; declare the property or the method, or give the class a named method for the lookup.', $class, $method);

        return RuleErrorBuilder::message($message)->identifier('intakt.noMagicMethod')->build();
    }
}
