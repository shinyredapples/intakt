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
        $collected = [
            ...array_merge(...array_values($node->get(TestMethodCollector::class))),
            ...$this->aliasedNames($node),
        ];
        $providers = array_merge(...array_values($node->get(TestProviderCollector::class)));
        $findings = [];

        foreach ($collected as [$file, $line, $owner, $class, $method, $mayBeProvider]) {
            // keyed by place, owner and name because a trait's method is collected once for every test case that uses it, but is one name to fix, while one line may hold several methods or classes
            if ($this->isUnderTests($file) && (!$mayBeProvider || (!$this->isProvider($class, $method, $providers) && !$this->isChangedFilesRun()))) {
                $findings[$file.':'.$line.':'.$owner.':'.$method] = $this->finding($owner, $method)->file($file)->line($line)->build();
            }
        }

        return array_values($findings);
    }

    /**
     * @return list<array{string, int, string, string, string, bool}>
     */
    private function aliasedNames(CollectedDataNode $node): array
    {
        $lines = $this->traitMethodLines($node);
        $judged = [];

        foreach (array_merge(...array_merge(...array_values($node->get(TraitAliasCollector::class)))) as [$file, $line, $owner, $class, $method, $mayBeProvider, $sourceFile, $sourceTrait, $exposer]) {
            // because a name the trait declares is one name to fix in the trait when the trait lives under tests/, and only the alias can be fixed for a trait outside tests/, while an alias declared outside tests/ is fixed in the test class that takes it
            $place = match (true) {
                null === $exposer && $this->isUnderTests($sourceFile) => [$sourceFile, $lines[strtolower($sourceTrait.'::'.$method)] ?? $line, $sourceTrait],
                null !== $exposer && !$this->isUnderTests($file) && $this->isUnderTests($sourceFile) => $exposer,
                default => [$file, $line, $owner],
            };
            $judged[] = [...$place, $class, $method, $mayBeProvider];
        }

        return $judged;
    }

    /**
     * @return array<string, int>
     */
    private function traitMethodLines(CollectedDataNode $node): array
    {
        $lines = [];

        foreach (array_merge(...array_merge(...array_values($node->get(TraitMethodLineCollector::class)))) as [$trait, $method, $line]) {
            $lines[strtolower($trait.'::'.$method)] = $line;
        }

        return $lines;
    }

    private function isUnderTests(string $file): bool
    {
        return str_starts_with($file, $this->root.'/tests/');
    }

    /**
     * @param list<array{references: list<array{string, string, string}>, runs: list<array{string, string, string}>}> $providers
     */
    private function isProvider(string $class, string $method, array $providers): bool
    {
        $references = $this->ofMethod(array_merge(...array_column($providers, 'references')), $method);
        $runs = $this->ofMethod(array_merge(...array_column($providers, 'runs')), $method);

        // because a concrete class that inherits a provider without naming it runs it as a plain test
        return $this->holds(array_column($references, 0), $class) && array_all($runs, fn (array $run): bool => 0 !== strcasecmp($run[0], $class) || $this->holds(array_column($references, 2), $run[2]));
    }

    /**
     * @param list<array{string, string, string}> $records
     *
     * @return list<array{string, string, string}>
     */
    private function ofMethod(array $records, string $method): array
    {
        return array_values(array_filter($records, static fn (array $record): bool => 0 === strcasecmp($record[1], $method)));
    }

    /**
     * @param list<string> $classes
     */
    private function holds(array $classes, string $class): bool
    {
        return array_any($classes, static fn (string $name): bool => 0 === strcasecmp($name, $class));
    }

    // a changed-files run may leave out the test that names a provider, so only a run with every test judges a static method that could return provider rows; only the gate's explicit signal turns this on, as for trait setters
    private function isChangedFilesRun(): bool
    {
        return '1' === getenv(StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE);
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
