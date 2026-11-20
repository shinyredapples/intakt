<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<FileNode>
 */
final readonly class NoStaticPropertyRule implements Rule
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
        $classes = array_filter(new NodeFinder()->findInstanceOf($node->getNodes(), ClassLike::class), $this->isJudged(...));

        return array_merge(...array_values(array_map($this->staticPropertiesOf(...), $classes)));
    }

    // a trait is judged because the class that uses it gets its static properties
    private function isJudged(ClassLike $class): bool
    {
        if ($class instanceof Trait_) {
            return true;
        }

        // PHPStan names an anonymous class, so only isAnonymous(), which it overrides for that node, tells one apart from a named class
        return $class instanceof Class_ && $class->name instanceof Identifier && !$class->isAnonymous() && !$class->isAbstract();
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function staticPropertiesOf(ClassLike $class): array
    {
        $findings = [];

        foreach (array_filter($class->getProperties(), static fn (Property $property): bool => $property->isStatic()) as $property) {
            foreach ($property->props as $item) {
                $findings[] = $this->staticProperty($class->name?->toString() ?? '', $item->name->toString(), $item->getStartLine());
            }
        }

        return $findings;
    }

    private function staticProperty(string $class, string $property, int $line): IdentifierRuleError
    {
        $message = sprintf('Property %s::$%s is static: no static state, because it outlives the request in a worker and leaks from one test into the next; keep the value in an instance property, a service or a local variable.', $class, $property);

        return RuleErrorBuilder::message($message)->identifier('intakt.noStaticProperty')->line($line)->build();
    }
}
