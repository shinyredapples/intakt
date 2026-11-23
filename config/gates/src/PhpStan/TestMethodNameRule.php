<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<CollectedDataNode>
 */
final readonly class TestMethodNameRule implements Rule
{
    private const int MINIMUM_WORDS = 3;

    // because an acronym reads as one word (testMCPToolWorks has three) and a lone digit is no word (testItSends2 has two)
    private const string WORD = '[A-Z](?:[a-z0-9]+|[A-Z0-9]*(?![a-z]))';

    public function __construct(private string $root)
    {
    }

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $methods = array_merge(...array_values($node->get(TestMethodCollector::class)));
        $providers = array_merge(...array_column($methods, 'provides'));
        $findings = [];

        foreach ($methods as $method) {
            if ($this->isJudged($method, $providers) && !$this->readsAsSentence($method['name'])) {
                // keyed by the declaration because a trait's method is collected once for each class that imports it
                $findings[$method['file'].':'.$method['line']] = $this->notASentence($method['name'], $method['file'], $method['line']);
            }
        }

        return array_values($findings);
    }

    /**
     * @param array{file: string, method: string, runnable: bool, overrides: list<string>} $method
     * @param list<string>                                                                 $providers
     */
    private function isJudged(array $method, array $providers): bool
    {
        // a method that implements or overrides a provider an ancestor names is that provider
        return $method['runnable'] && str_starts_with($method['file'], $this->root.'/tests/') && [] === array_intersect([$method['method'], ...$method['overrides']], $providers);
    }

    private function readsAsSentence(string $name): bool
    {
        $prefixed = 1 === preg_match('/^'.TestMethodCollector::PREFIX.'((?:'.self::WORD.')+)$/', $name, $found);

        return $prefixed && preg_match_all('/'.self::WORD.'/', $found[1]) >= self::MINIMUM_WORDS;
    }

    private function notASentence(string $name, string $file, int $line): IdentifierRuleError
    {
        $message = sprintf('Test method %s() does not read as a sentence: name it test followed by at least three words in camel case, for example testAStaleLockIsReleased() (the prefix is required, also on a #[Test] method, and the first word starts with a capital); a data provider named by PHPUnit\'s #[DataProvider] or #[DataProviderExternal] is exempt.', $name);

        return RuleErrorBuilder::message($message)->identifier('intakt.testName')->file($file)->line($line)->build();
    }
}
