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
final readonly class NoPublicSetterRule implements Rule
{
    // "set" not followed by a lowercase letter, so setName() and set_name() are setters while settle() and setup() are words of their own
    private const string SETTER = '~^set(?![a-z])~';

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

        if (!$class instanceof ClassReflection || !$this->isPublicSetter($node) || !$this->repositoryCode->contains($scope->getFile())) {
            return [];
        }

        if ($this->vendorDeclares($class, $node->name->toString())) {
            return [];
        }

        return [$this->publicSetter($class->getDisplayName(), $node->name->toString())];
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

        return false === $file || !$this->repositoryCode->contains($file);
    }

    private function publicSetter(string $class, string $method): IdentifierRuleError
    {
        $message = sprintf('Method %s::%s() is a public setter: no public setters, because an object changed after construction can be seen half set up and changed by any caller; pass the value to the constructor, or return a new instance from a with*() method (clone() with overrides).', $class, $method);

        return RuleErrorBuilder::message($message)->identifier('intakt.noPublicSetter')->build();
    }
}
