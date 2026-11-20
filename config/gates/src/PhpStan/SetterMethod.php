<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node\Stmt\ClassMethod;

// one definition of a setter for the rule and its collectors, so what a trait collects cannot drift from what the rule reports
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
}
