<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;

// one definition of a setter and its finding for both setter rules and their collectors, so what a trait collects cannot drift from what the rules report
final readonly class SetterMethod
{
    // "set" in any letter case, since PHP matches method names case-insensitively, not followed by a lowercase letter, so setName(), SetName() and set_name() are setters while settle(), setup() and Settings() are words of their own
    private const string NAME = '~^[sS][eE][tT](?![a-z])~';

    public function isPublicSetter(ClassMethod $method): bool
    {
        return $method->isPublic() && $this->isSetterName($method->name->toString());
    }

    public function isSetterName(string $name): bool
    {
        return 1 === preg_match(self::NAME, $name);
    }

    /**
     * @return RuleErrorBuilder<IdentifierRuleError>
     */
    public function finding(string $class, string $method): RuleErrorBuilder
    {
        $message = sprintf('Method %s::%s() is a public setter: no public setters, because an object changed after construction can be seen half set up and changed by any caller; pass the value to the constructor, or return a new instance from a with*() method (clone() with overrides).', $class, $method);

        return RuleErrorBuilder::message($message)->identifier('intakt.noPublicSetter');
    }
}
