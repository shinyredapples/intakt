<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @implements Rule<FileNode>
 */
final readonly class NoRequiredAttributeRule implements Rule
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

        // the whole file is searched once, so an attribute in a trait no class uses or in a class declared inside a function counts too
        $attributes = new NodeFinder()->findInstanceOf($node->getNodes(), Attribute::class);
        // PHPStan's parser has already resolved each name against the imports, so an alias still counts, and PHP matches class names in any letter case
        $required = array_filter($attributes, static fn (Attribute $attribute): bool => 0 === strcasecmp(ltrim($attribute->name->toString(), '\\'), Required::class));

        return array_values(array_map($this->requiredAttribute(...), $required));
    }

    private function requiredAttribute(Attribute $attribute): IdentifierRuleError
    {
        $message = 'Attribute #[Required] wires a dependency after construction: constructor injection only, because a service built in two steps can be used half wired and hides the dependency from its constructor; take the dependency as a constructor parameter.';

        return RuleErrorBuilder::message($message)->identifier('intakt.constructorInjectionOnly')->line($attribute->getStartLine())->build();
    }
}
