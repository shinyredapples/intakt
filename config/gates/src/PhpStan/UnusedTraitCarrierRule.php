<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use Intakt\Gates\StaticAnalysisGate;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

// because a trait no analyzed class uses has no method types, any class it names only at run time may be a role prompt carrier; RawPromptMessageRule judges a used trait's with its method's types
/**
 * @implements Rule<CollectedDataNode>
 */
final readonly class UnusedTraitCarrierRule implements Rule
{
    private RawPromptMessageRule $judged;

    private RolePromptCarriers $carriers;

    public function __construct(string $root)
    {
        $this->judged = new RawPromptMessageRule($root);
        $this->carriers = new RolePromptCarriers(new ClassNames());
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
        // A subset run misses the classes that use a trait, so only the gate's explicit signal skips this, as for trait setters
        if ('1' === getenv(StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE)) {
            return [];
        }

        $used = array_merge(...array_merge(...array_values($node->get(UsedTraitCollector::class))));
        $findings = [];

        foreach ($node->get(RunTimeTraitConstructionCollector::class) as $file => $traits) {
            $unused = $this->judged->isJudgedFile($file) ? array_filter($traits, static fn (array $trait): bool => !array_any($used, static fn (string $name): bool => 0 === strcasecmp($name, $trait[0]))) : [];
            $findings = [...$findings, ...array_map(fn (int $line): IdentifierRuleError => $this->carriers->unusedTraitFinding($file, $line), array_merge(...array_column($unused, 1)))];
        }

        return $findings;
    }
}
