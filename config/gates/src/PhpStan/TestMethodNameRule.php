<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantStringType;
use PHPUnit\Framework\Attributes\Test;

/**
 * @implements Rule<InClassNode>
 */
final readonly class TestMethodNameRule implements Rule
{
    private const string PREFIX = 'test';

    private const string TEST_ATTRIBUTE = Test::class;

    private const int MINIMUM_WORDS = 3;

    // a run of capitals is one word (testMCPToolWorks counts MCP, Tool and Works), and digits belong to the word they follow
    private const string WORD = '[A-Z](?:[a-z0-9]+|[A-Z0-9]*(?![a-z]))';

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
        $providers = $this->providersOf($methods, $scope);
        $judged = array_filter($methods, fn (ClassMethod $method): bool => $this->isJudged($method, $providers));
        $misnamed = array_filter($judged, fn (ClassMethod $method): bool => !$this->readsAsSentence($method->name->toString()));

        return array_values(array_map($this->notASentence(...), $misnamed));
    }

    /**
     * @param list<ClassMethod> $methods
     *
     * @return list<string>
     */
    private function providersOf(array $methods, Scope $scope): array
    {
        $names = [];

        foreach ($methods as $method) {
            foreach ($method->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    array_push($names, ...$this->providersNamedBy($attribute, $scope));
                }
            }
        }

        return $names;
    }

    /**
     * @param list<string> $providers
     */
    private function isJudged(ClassMethod $method, array $providers): bool
    {
        $name = $method->name->toString();
        $runnable = str_starts_with($name, self::PREFIX) || $this->isMarkedAsTest($method);

        return $method->isPublic() && $runnable && !in_array($name, $providers, true);
    }

    private function readsAsSentence(string $name): bool
    {
        $words = ucfirst(str_starts_with($name, self::PREFIX) ? substr($name, strlen(self::PREFIX)) : $name);

        return 1 === preg_match('/^(?:'.self::WORD.')+$/', $words) && preg_match_all('/'.self::WORD.'/', $words) >= self::MINIMUM_WORDS;
    }

    private function notASentence(ClassMethod $method): IdentifierRuleError
    {
        $name = $method->name->toString();
        $message = sprintf('Test method %s() does not read as a sentence: name it test followed by at least three words in camel case, for example testAStaleLockIsReleased() (a #[Test] method without the prefix needs the three words alone); a data provider named by #[DataProvider] is exempt.', $name);

        return RuleErrorBuilder::message($message)->identifier('intakt.testName')->line($method->name->getStartLine())->build();
    }

    /**
     * @return list<string>
     */
    private function providersNamedBy(Attribute $attribute, Scope $scope): array
    {
        $first = $attribute->args[0]->value ?? null;

        if ('DataProvider' !== $attribute->name->getLast() || null === $first) {
            return [];
        }

        return array_map(static fn (ConstantStringType $name): string => $name->getValue(), $scope->getType($first)->getConstantStrings());
    }

    private function isMarkedAsTest(ClassMethod $method): bool
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (self::TEST_ATTRIBUTE === $attribute->name->toString()) {
                    return true;
                }
            }
        }

        return false;
    }
}
