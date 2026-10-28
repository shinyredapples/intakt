<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use Doctrine\ORM\Mapping\Embeddable;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\MappedSuperclass;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionProperty;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\AttributeReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * @implements Rule<InClassNode>
 */
final readonly class StatefulServiceResetRule implements Rule
{
    // services.yaml registers src/ and nothing else, so a class elsewhere is never a service: https://symfony.com/doc/current/service_container.html#importing-many-services-at-once-with-resource
    private const string REGISTERED_RESOURCE = '/src/';

    // inside src/ only what the container skips is not a service, so these attributes exempt a class
    private const array NOT_REGISTERED = [
        Exclude::class,
        Entity::class,
        Embeddable::class,
        MappedSuperclass::class,
    ];

    public function __construct(private string $root)
    {
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();

        if (!$this->isRegistered($scope) || !$this->isService($class)) {
            return [];
        }

        return array_map(
            fn (ReflectionProperty $property): IdentifierRuleError => $this->finding($class, $property, $node->getStartLine()),
            $this->mutableProperties($class),
        );
    }

    private function isRegistered(Scope $scope): bool
    {
        return str_starts_with($scope->getFile(), $this->root.self::REGISTERED_RESOURCE);
    }

    private function isService(ClassReflection $class): bool
    {
        return $class->isClass()
            && !$class->isAnonymous()
            && !$class->isAbstract()
            && !$class->implementsInterface(ResetInterface::class)
            && !$class->is(Throwable::class)
            && !$class->is(KernelInterface::class)
            && !$this->isExcludedFromTheContainer($class);
    }

    private function isExcludedFromTheContainer(ClassReflection $class): bool
    {
        return array_any($class->getAttributes(), static fn (AttributeReflection $attribute): bool => in_array($attribute->getName(), self::NOT_REGISTERED, true));
    }

    /**
     * @return list<ReflectionProperty>
     */
    private function mutableProperties(ClassReflection $class): array
    {
        $declared = array_map($this->declaredProperties(...), [$class, ...$class->getParents()]);

        return array_values(array_filter(array_merge(...$declared), static fn (ReflectionProperty $property): bool => !$property->isReadOnly()));
    }

    /**
     * @return list<ReflectionProperty>
     */
    private function declaredProperties(ClassReflection $class): array
    {
        $properties = $class->getNativeReflection()->getProperties();

        return array_values(array_filter($properties, static fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === $class->getName()));
    }

    private function finding(ClassReflection $class, ReflectionProperty $property, int $classLine): IdentifierRuleError
    {
        $origin = $property->getBetterReflection()->getDeclaringClass();
        $ownLine = $origin->getName() === $class->getName() ? $property->getBetterReflection()->getStartLine() : null;
        $name = null === $ownLine ? sprintf('%s::$%s', $origin->getShortName(), $property->getName()) : '$'.$property->getName();
        $message = sprintf('Service property %s is not readonly and the class does not implement ResetInterface: make the property readonly or implement reset() so Symfony clears it between requests and messages.', $name);

        return RuleErrorBuilder::message($message)->identifier('intakt.statefulService')->line($ownLine ?? $classLine)->build();
    }
}
