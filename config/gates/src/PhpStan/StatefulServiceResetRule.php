<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * @implements Rule<InClassNode>
 */
final readonly class StatefulServiceResetRule implements Rule
{
    // Value objects, entities and DTOs live in these layers; only services live elsewhere.
    private const array DATA_LAYERS = ['Domain', 'Contract'];
    private const array MAPPED_ATTRIBUTES = [
        'Doctrine\ORM\Mapping\Entity',
        'Doctrine\ORM\Mapping\Embeddable',
        'Doctrine\ORM\Mapping\MappedSuperclass',
    ];

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getOriginalNode();

        if (!$class instanceof Class_ || $class->isReadonly() || !$this->isService($node->getClassReflection())) {
            return [];
        }

        return array_map($this->finding(...), $this->mutableProperties($class));
    }

    private function isService(ClassReflection $reflection): bool
    {
        return !$reflection->isAnonymous()
            && !$reflection->implementsInterface(ResetInterface::class)
            && !$reflection->is(Throwable::class)
            && !$reflection->is(TestCase::class)
            && !$this->isMapped($reflection)
            && [] === array_intersect(self::DATA_LAYERS, explode('\\', $reflection->getName()));
    }

    private function isMapped(ClassReflection $reflection): bool
    {
        foreach ($reflection->getAttributes() as $attribute) {
            if (in_array($attribute->getName(), self::MAPPED_ATTRIBUTES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{string, int}> name and line of each property that can change
     */
    private function mutableProperties(Class_ $class): array
    {
        $found = [];

        foreach ($class->stmts as $statement) {
            if ($statement instanceof Property && !$statement->isStatic() && !$statement->isReadonly()) {
                foreach ($statement->props as $property) {
                    $found[] = [$property->name->toString(), $property->getStartLine()];
                }
            }

            if ($statement instanceof ClassMethod && '__construct' === $statement->name->toLowerString()) {
                array_push($found, ...$this->mutablePromotedProperties($statement));
            }
        }

        return $found;
    }

    /**
     * @return list<array{string, int}>
     */
    private function mutablePromotedProperties(ClassMethod $constructor): array
    {
        $found = [];

        foreach ($constructor->params as $parameter) {
            if ($this->isMutablePromotion($parameter) && $parameter->var instanceof Variable && is_string($parameter->var->name)) {
                $found[] = [$parameter->var->name, $parameter->getStartLine()];
            }
        }

        return $found;
    }

    private function isMutablePromotion(Param $parameter): bool
    {
        return 0 !== $parameter->flags && 0 === ($parameter->flags & Modifiers::READONLY);
    }

    /**
     * @param array{string, int} $property
     */
    private function finding(array $property): IdentifierRuleError
    {
        [$name, $line] = $property;
        $message = sprintf('Service property $%s is not readonly and the class does not implement ResetInterface: make the property readonly or implement reset() so Symfony clears it between requests and messages.', $name);

        return RuleErrorBuilder::message($message)->identifier('intakt.statefulService')->line($line)->build();
    }
}
