<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Class_>
 */
final readonly class FinalClassRule implements Rule
{
    private const array REPOSITORY_CODE = ['/src/', '/tests/', '/bin/', '/config/gates/src/'];

    public function __construct(private string $root)
    {
    }

    public function getNodeType(): string
    {
        return Class_::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // PHPStan names an anonymous class, so only isAnonymous(), which it overrides for that node, tells one apart from a named class
        if (null === $node->name || $node->isAnonymous() || $node->isFinal() || $node->isAbstract() || !$this->isRepositoryCode($scope->getFile())) {
            return [];
        }

        $message = sprintf('Class %s is not final: declare it final, or abstract when it is meant to be extended.', $node->name->toString());

        return [RuleErrorBuilder::message($message)->identifier('intakt.finalClass')->line($node->name->getStartLine())->build()];
    }

    private function isRepositoryCode(string $file): bool
    {
        return array_any(self::REPOSITORY_CODE, fn (string $folder): bool => str_starts_with($file, $this->root.$folder));
    }
}
