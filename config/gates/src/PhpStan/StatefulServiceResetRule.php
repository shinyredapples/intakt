<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use Doctrine\ORM\Mapping\Embeddable;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\MappedSuperclass;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\AttributeReflection;
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
    // Value objects, entities and DTOs live in these layers, so only classes elsewhere are services.
    private const array DATA_LAYERS = ['Domain', 'Contract'];
    private const array MAPPED_ATTRIBUTES = [
        Entity::class,
        Embeddable::class,
        MappedSuperclass::class,
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
        return array_any($reflection->getAttributes(), static fn (AttributeReflection $attribute): bool => in_array($attribute->getName(), self::MAPPED_ATTRIBUTES, true));
    }

    /**
     * @return list<array{string, int}>
     */
    private function mutableProperties(Class_ $class): array
    {
        $declared = array_map($this->declaredProperties(...), $class->stmts);

        return array_merge(...$declared);
    }

    /**
     * @return list<array{string, int}>
     */
    private function declaredProperties(Stmt $statement): array
    {
        return match (true) {
            $statement instanceof Property && !$statement->isStatic() && !$statement->isReadonly() => array_values(array_map(
                static fn (PropertyItem $property): array => [$property->name->toString(), $property->getStartLine()],
                $statement->props,
            )),
            $statement instanceof ClassMethod && '__construct' === $statement->name->toLowerString() => $this->mutablePromotedProperties($statement),
            default => [],
        };
    }

    /**
     * @return list<array{string, int}>
     */
    private function mutablePromotedProperties(ClassMethod $constructor): array
    {
        $promoted = array_filter($constructor->params, $this->isMutablePromotion(...));

        return array_values(array_map(
            static fn (Param $parameter): array => [$parameter->var instanceof Variable && is_string($parameter->var->name) ? $parameter->var->name : '', $parameter->getStartLine()],
            $promoted,
        ));
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
