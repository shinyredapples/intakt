<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;

final readonly class DeprecatedClasses
{
    public function __construct(
        private ReflectionProvider $reflections,
        private FileScopeDeprecation $fileScope,
    ) {
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function reportUseOf(string $name, int $line): array
    {
        if (!$this->isDeprecated($name)) {
            return [];
        }

        $message = "Use of the deprecated class, interface or trait {$name}: deprecated code is not used, switch to its replacement.";

        return [RuleErrorBuilder::message($message)->identifier('intakt.deprecatedReference')->line($line)->build()];
    }

    public function isDeprecated(string $name): bool
    {
        if (!$this->reflections->hasClass($name)) {
            return false;
        }

        $class = $this->reflections->getClass($name);

        // because a vendor class that only warns when its file loads carries no tag for reflection to find
        return $class->isDeprecated() || $this->fileScope->triggeredBy($class->getFileName());
    }
}
