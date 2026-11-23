<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use Intakt\Gates\StaticAnalysisGate;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<CollectedDataNode>
 */
final readonly class NoPublicTraitSetterRule implements Rule
{
    private RepositoryCode $repositoryCode;

    private SetterMethod $setters;

    private VendorSources $vendorSources;

    public function __construct(string $root)
    {
        $this->repositoryCode = new RepositoryCode($root);
        $this->setters = new SetterMethod();
        $this->vendorSources = new VendorSources($root);
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
        // A subset run misses the classes that use a trait and would report their vendor setters; only the gate's explicit signal turns the check off, so a direct phpstan call, a test or a full run judges trait setters.
        if ('1' === getenv(StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE)) {
            return [];
        }

        $uses = array_merge(...array_values($node->get(TraitSetterUseCollector::class)), ...array_merge(...array_values($node->get(AbstractTraitSetterUseCollector::class))));
        $findings = [];

        foreach ($node->get(TraitSetterDeclarationCollector::class) as $file => $traits) {
            $declared = $this->repositoryCode->contains($file) ? array_merge(...$traits) : [];
            $avoidable = array_filter($declared, fn (array $setter): bool => !$this->vendorRequiresInEveryUse($setter[0], $setter[1], $uses));
            $findings = [...$findings, ...array_map(fn (array $setter): IdentifierRuleError => $this->setters->finding($setter[0], $setter[1])->file($file)->line($setter[2])->build(), array_values($avoidable))];
        }

        return $findings;
    }

    // a trait no analyzed class uses is reported because no vendor ancestor can require the name there, and one using class without such an ancestor is enough, since the name is then the repository's choice
    /**
     * @param list<array{string, string, list<string>}> $uses
     */
    private function vendorRequiresInEveryUse(string $trait, string $method, array $uses): bool
    {
        $ofMethod = array_filter($uses, static fn (array $use): bool => $trait === $use[0] && $method === $use[1]);

        return [] !== $ofMethod && array_all($ofMethod, fn (array $use): bool => array_any($use[2], $this->vendorSources->contain(...)));
    }
}
