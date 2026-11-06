<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Symfony\ServiceDefinition;
use PHPStan\Symfony\ServiceMap;

// A service map without services that reads ids as phpstan-symfony does, so a rule handed it reports every constant id it asks about.
final readonly class NoServices implements ServiceMap
{
    public function getServices(): array
    {
        return [];
    }

    public function getService(string $id): ?ServiceDefinition
    {
        return null;
    }

    public static function getServiceIdFromNode(Expr $node, Scope $scope): ?string
    {
        $ids = $scope->getType($node)->getConstantStrings();

        return 1 === count($ids) ? $ids[0]->getValue() : null;
    }
}
