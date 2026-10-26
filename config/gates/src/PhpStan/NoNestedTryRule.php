<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\TryCatch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<TryCatch>
 */
final readonly class NoNestedTryRule implements Rule
{
    public function getNodeType(): string
    {
        return TryCatch::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $nested = $this->firstTryIn($this->bodiesOf($node));

        if (!$nested instanceof TryCatch) {
            return [];
        }

        return [
            RuleErrorBuilder::message('A try block inside another try, catch or finally of the same function: extract the inner one into a method.')
                ->identifier('intakt.nestedTry')
                ->line($nested->getStartLine())
                ->build(),
        ];
    }

    /**
     * @return list<Node>
     */
    private function bodiesOf(TryCatch $node): array
    {
        $catchBodies = array_merge(...array_map(static fn (Catch_ $catch): array => $catch->stmts, $node->catches));

        return array_values([...$node->stmts, ...$catchBodies, ...($node->finally->stmts ?? [])]);
    }

    /**
     * @param array<mixed> $nodes
     */
    private function firstTryIn(array $nodes): ?TryCatch
    {
        foreach ($nodes as $node) {
            $found = match (true) {
                $node instanceof Node => $this->tryIn($node),
                is_array($node) => $this->firstTryIn($node),
                default => null,
            };

            if ($found instanceof TryCatch) {
                return $found;
            }
        }

        return null;
    }

    private function tryIn(Node $node): ?TryCatch
    {
        if ($node instanceof TryCatch) {
            return $node;
        }

        if ($node instanceof FunctionLike || $node instanceof ClassLike) {
            return null;
        }

        return $this->firstTryIn(array_intersect_key(get_object_vars($node), array_flip($node->getSubNodeNames())));
    }
}
