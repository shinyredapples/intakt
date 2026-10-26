<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * @implements Rule<Attribute>
 */
final readonly class NoMockOptOutRule implements Rule
{
    private const string OPT_OUT = AllowMockObjectsWithoutExpectations::class;

    public function getNodeType(): string
    {
        return Attribute::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (0 !== strcasecmp(ltrim($node->name->toString(), '\\'), self::OPT_OUT)) {
            return [];
        }

        return [RuleErrorBuilder::message('#[AllowMockObjectsWithoutExpectations] hides a mock that checks nothing: use createStub() instead.')->identifier('intakt.mockOptOut')->build()];
    }
}
