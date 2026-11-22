<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use Doctrine\DBAL\Types\Type;
use Intakt\Core\Platform\Contract\KeptForTheProcess;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionProperty;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * @implements Rule<InClassNode>
 */
final readonly class StatefulServiceResetRule implements Rule
{
    public function __construct(private ContainerRegistration $registration)
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
        return $this->registration->covers($scope->getFile());
    }

    private function isService(ClassReflection $class): bool
    {
        return $class->isClass()
            && !$class->isAnonymous()
            && !$class->isAbstract()
            && !$class->implementsInterface(ResetInterface::class)
            && !$class->is(Throwable::class)
            && !$class->is(KernelInterface::class)
            // Doctrine creates column types itself, so the container never holds one
            && !$class->is(Type::class)
            && !$this->registration->excludes($class);
    }

    private function finding(ClassReflection $class, ReflectionProperty $property, int $classLine): IdentifierRuleError
    {
        $origin = $property->getBetterReflection()->getDeclaringClass();
        $ownLine = $origin->getName() === $class->getName() ? $property->getBetterReflection()->getStartLine() : null;
        $name = null === $ownLine ? sprintf('%s::$%s', $origin->getShortName(), $property->getName()) : '$'.$property->getName();
        $message = sprintf('Service property %s is not readonly and the class does not implement ResetInterface: make the property readonly or implement reset() so Symfony clears it between requests and messages, or mark state meant to last the whole process with #[KeptForTheProcess].', $name);

        return RuleErrorBuilder::message($message)->identifier('intakt.statefulService')->line($ownLine ?? $classLine)->build();
    }

    /**
     * @return list<ReflectionProperty>
     */
    private function mutableProperties(ClassReflection $class): array
    {
        $declared = array_map($this->declaredProperties(...), [$class, ...$class->getParents()]);

        // a virtual property has no backing store, so it holds no state to carry into the next request
        return array_values(array_filter(array_merge(...$declared), fn (ReflectionProperty $property): bool => !$property->isReadOnly() && !$property->isVirtual() && !$this->isKeptForTheProcess($property) && $this->isDeclaredInTheRegisteredResource($property)));
    }

    /**
     * @return list<ReflectionProperty>
     */
    private function declaredProperties(ClassReflection $class): array
    {
        $properties = $class->getNativeReflection()->getProperties();

        return array_values(array_filter($properties, static fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === $class->getName()));
    }

    private function isKeptForTheProcess(ReflectionProperty $property): bool
    {
        // an explicit marker, because state meant to outlive every reset must be visible to a reviewer on the property instead of hiding in an object behind a readonly one
        return [] !== $property->getAttributes(KeptForTheProcess::class);
    }

    private function isDeclaredInTheRegisteredResource(ReflectionProperty $property): bool
    {
        // a vendor base class (Command::$definition, AbstractController::$container) holds framework wiring, not request state, so only a property declared under src/ counts
        $file = $property->getBetterReflection()->getDeclaringClass()->getFileName();

        return null !== $file && $this->registration->covers($file);
    }
}
