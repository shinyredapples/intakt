<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Symfony\ServiceDefinition;
use PHPStan\Symfony\ServiceMap;
use PHPStan\Type\ObjectType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

// phpstan-symfony checks a get() id only when it is one constant string, so a misspelled or private branch of a union fails at runtime
/**
 * @implements Rule<MethodCall>
 */
final readonly class ContainerUnknownGetIdRule implements Rule
{
    public function __construct(private ServiceMap $services, private AskedServiceIds $askedIds)
    {
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $ids = $this->askedIds->of($node, $scope, 'get');

        // because phpstan-symfony already reports a single constant id, and a second finding would only repeat it
        if (count($ids) < 2) {
            return [];
        }

        $unknown = array_filter($ids, fn (string $id): bool => !$this->services->getService($id) instanceof ServiceDefinition);
        $private = $this->reachesPrivateServices($node, $scope) ? [] : array_filter($ids, fn (string $id): bool => false === $this->services->getService($id)?->isPublic());

        // because these are the identifiers and messages phpstan-symfony gives for a single id, so both read and are searched the same way
        return [
            ...array_map(static fn (string $id): IdentifierRuleError => RuleErrorBuilder::message(sprintf('Service "%s" is not registered in the container.', $id))->identifier('symfonyContainer.serviceNotFound')->build(), array_values($unknown)),
            ...array_map(static fn (string $id): IdentifierRuleError => RuleErrorBuilder::message(sprintf('Service "%s" is private.', $id))->identifier('symfonyContainer.privateService')->build(), array_values($private)),
        ];
    }

    // because the test container and a subscriber's locator hand out private services, as phpstan-symfony's private service rule allows
    private function reachesPrivateServices(MethodCall $call, Scope $scope): bool
    {
        $receiver = $scope->getType($call->var);
        $caller = $scope->getClassReflection();

        if (new ObjectType(TestContainer::class)->isSuperTypeOf($receiver)->yes()) {
            return true;
        }

        return $caller instanceof ClassReflection && array_any(
            [KernelTestCase::class, ServiceSubscriberInterface::class],
            static fn (string $reaching): bool => $caller->is($reaching),
        );
    }
}
