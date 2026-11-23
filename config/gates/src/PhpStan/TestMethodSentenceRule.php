<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use Intakt\Gates\StaticAnalysisGate;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<CollectedDataNode>
 */
final readonly class TestMethodSentenceRule implements Rule
{
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
        $collected = array_merge(...array_values($node->get(TestMethodCollector::class)));
        $providers = array_merge(...array_column($collected, 'providers'));
        $findings = [];

        foreach (array_filter(array_column($collected, 'misnamed')) as [$file, $line, $owner, $class, $method, $static]) {
            // keyed by place because a trait's method is collected once for every test case that uses it, but is one name to fix
            if (str_starts_with($file, $this->root.'/tests/') && !$this->isNamedProvider($class, $method, $providers) && !$this->mayBeProviderOfUnanalyzedTest($static)) {
                $findings[$file.':'.$line] = $this->finding($owner, $method)->file($file)->line($line)->build();
            }
        }

        return array_values($findings);
    }

    /**
     * @param list<array{string, string}> $providers
     */
    private function isNamedProvider(string $class, string $method, array $providers): bool
    {
        return array_any($providers, static fn (array $provider): bool => 0 === strcasecmp($provider[0], $class) && 0 === strcasecmp($provider[1], $method));
    }

    // PHPUnit wants a provider static, and a changed-files run may leave out the test that names it, so only a run with every test judges a static method; only the gate's explicit signal turns this on, as for trait setters
    private function mayBeProviderOfUnanalyzedTest(bool $static): bool
    {
        return $static && '1' === getenv(StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE);
    }

    /**
     * @return RuleErrorBuilder<IdentifierRuleError>
     */
    private function finding(string $owner, string $method): RuleErrorBuilder
    {
        $message = sprintf('Test method %s::%s() is not named as a sentence: name it test followed by at least three camel-case words that state the behavior, such as testTheFormIsSent().', $owner, $method);

        return RuleErrorBuilder::message($message)->identifier('intakt.testMethodSentence');
    }
}
