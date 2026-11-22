<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<InClassNode>
 */
final readonly class TestMethodNameRule implements Rule
{
    private const string PREFIX = 'test';

    private const int MINIMUM_WORDS = 3;

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
        if (!str_starts_with($scope->getFile(), $this->root.'/tests/')) {
            return [];
        }

        $methods = $node->getOriginalNode()->getMethods();
        $providers = $this->providersOf($methods);
        $judged = array_filter($methods, fn (ClassMethod $method): bool => $this->isJudged($method, $providers));

        return array_values(array_map($this->notASentence(...), array_filter($judged, fn (ClassMethod $method): bool => !$this->readsAsSentence($method->name->toString()))));
    }

    /**
     * @param list<ClassMethod> $methods
     *
     * @return list<string>
     */
    private function providersOf(array $methods): array
    {
        $names = [];

        foreach ($methods as $method) {
            foreach ($method->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    $names[] = $this->providerNamed($attribute);
                }
            }
        }

        return array_values(array_filter($names, is_string(...)));
    }

    private function providerNamed(Attribute $attribute): ?string
    {
        $isProvider = 'DataProvider' === $attribute->name->getLast();
        $first = $attribute->args[0]->value ?? null;

        return $isProvider && $first instanceof String_ ? $first->value : null;
    }

    /**
     * @param list<string> $providers
     */
    private function isJudged(ClassMethod $method, array $providers): bool
    {
        $name = $method->name->toString();

        return $method->isPublic() && str_starts_with($name, self::PREFIX) && !in_array($name, $providers, true);
    }

    private function notASentence(ClassMethod $method): IdentifierRuleError
    {
        $name = $method->name->toString();
        $message = sprintf('Test method %s() does not read as a sentence: name it test followed by at least three words in camel case, for example testAStaleLockIsReleased(); a data provider named by #[DataProvider] is exempt.', $name);

        return RuleErrorBuilder::message($message)->identifier('intakt.testName')->line($method->name->getStartLine())->build();
    }

    private function readsAsSentence(string $name): bool
    {
        $words = substr($name, strlen(self::PREFIX));

        // a run of capitals is one word, so testMCPToolWorks counts MCP, Tool and Works
        return 1 === preg_match('/^[A-Z0-9]/', $words) && preg_match_all('/[A-Z]+(?![a-z])|[A-Z]?[a-z]+|[0-9]+/', $words) >= self::MINIMUM_WORDS;
    }
}
