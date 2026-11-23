<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<FileNode>
 */
final readonly class FinalClassRule implements Rule
{
    private RepositoryCode $repositoryCode;

    public function __construct(string $root)
    {
        $this->repositoryCode = new RepositoryCode($root);
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->repositoryCode->contains($scope->getFile())) {
            return [];
        }

        // a class can also be declared inside a function body or a condition, so the whole file is searched once instead of relying on where PHPStan visits class nodes
        $classes = new NodeFinder()->findInstanceOf($node->getNodes(), Class_::class);

        return array_values(array_map($this->notFinal(...), array_filter($classes, $this->requiresFinalModifier(...))));
    }

    private function notFinal(Class_ $class): IdentifierRuleError
    {
        $name = $class->name?->toString() ?? '';
        $message = sprintf('Class %s is not final: declare it final, or abstract when it is meant to be extended.', $name);

        return RuleErrorBuilder::message($message)->identifier('intakt.finalClass')->line($class->name?->getStartLine() ?? $class->getStartLine())->build();
    }

    private function requiresFinalModifier(Class_ $class): bool
    {
        // PHPStan names an anonymous class, so only isAnonymous(), which it overrides for that node, tells one apart from a named class
        return $class->name instanceof Identifier && !$class->isAnonymous() && !$class->isFinal() && !$class->isAbstract();
    }
}
