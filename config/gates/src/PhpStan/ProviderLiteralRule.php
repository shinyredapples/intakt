<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<FileNode>
 */
final readonly class ProviderLiteralRule implements Rule
{
    // only src/, because tests pin provider answers with real ids, and the demo data file config/sample-project.yaml is where the sample project's ids belong
    private const string CHECKED = '/src/';

    // the model families a tier names today, matched only where an id starts, so a provider label or a Provider value such as claude_code stays allowed; a bare o-series name counts only with its known variants and date, or where an id stands (after a router prefix such as openai/ or openai: or an equals sign, up to any word boundary; or as the whole literal, after a dot or a quote, up to the end, a closing quote or a :tag), because prose such as "o2 sensor" or o3-compatible names no model
    private const string MODEL_ID = '~(?<![\w-])(?:claude-(?:opus|sonnet|haiku|instant|\d)|(?:chat)?gpt-(?:\d|oss|image)|o\d(?:-(?:mini|pro|preview|deep-research|\d{4}-\d{2}-\d{2}))+(?![\w-])|(?<=[/=:])o\d(?![\w-])|(?<![^."\'])o\d(?=$|["\']|:\w)|text-embedding-(?:\d|ada(?![a-z]))|codex-mini(?![a-z])|gemini-\d|kimi-k\d|llama-?\d|deepseek-(?:[rv]\d|(?:chat|reasoner|coder)(?![a-z]))|qwen\d|mistral-(?:large|medium|small))[\w.:-]*~i';

    // the domains of the model providers and of the coding agents Intakt runs, with any subdomain, so a host counts on its own and inside a URL
    private const string PROVIDER_HOST = '~(?<![\w.-])(?:[\w-]+\.)*(?:anthropic\.com|claude\.ai|claude\.com|openai\.com|chatgpt\.com|huggingface\.co)(?![\w-]|\.[\w-])~i';

    public function __construct(
        private string $root,
        private ReflectionProvider $reflections,
        private string $catalog,
    ) {
    }

    // the whole file rather than each literal, because PHPStan never analyses the body of a trait that no class uses
    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!str_starts_with($scope->getFile(), $this->root.self::CHECKED)) {
            return [];
        }

        $inTheCatalog = $this->literalsOfTheCatalog($node->getNodes());
        $findings = [];

        foreach (new NodeFinder()->find($node->getNodes(), $this->isLiteral(...)) as $literal) {
            $message = isset($inTheCatalog[spl_object_id($literal)]) ? null : $this->messageFor($literal);

            if (null !== $message) {
                $findings[] = RuleErrorBuilder::message($message)->line($literal->getStartLine())->identifier('intakt.providerLiteral')->build();
            }
        }

        return $findings;
    }

    /**
     * @param array<Node> $nodes
     *
     * @return array<int, true>
     */
    private function literalsOfTheCatalog(array $nodes): array
    {
        $finder = new NodeFinder();
        $literals = [];

        foreach ($finder->findInstanceOf($nodes, ClassLike::class) as $class) {
            if (!$this->isInTheCatalog($class)) {
                continue;
            }

            foreach ($finder->find([$class], $this->isLiteral(...)) as $literal) {
                $literals[spl_object_id($literal)] = true;
            }
        }

        return $literals;
    }

    // every text part of an interpolated string, because an id can follow the interpolation as well as lead it
    private function isLiteral(Node $node): bool
    {
        return $node instanceof String_ || $node instanceof InterpolatedStringPart;
    }

    // a named class that implements the descriptor contract, read through reflection, so a class only named like a descriptor is still checked; an anonymous class has no namespacedName at all, so it counts only inside a descriptor
    private function isInTheCatalog(ClassLike $class): bool
    {
        if (!$class->name instanceof Identifier) {
            return false;
        }

        $name = $class->namespacedName?->toString() ?? '';

        return $this->reflections->hasClass($name)
            && $this->reflections->getClass($name)->implementsInterface($this->catalog);
    }

    private function messageFor(Node $literal): ?string
    {
        $text = $literal instanceof String_ || $literal instanceof InterpolatedStringPart ? $literal->value : '';
        $model = $this->firstMatchOf(self::MODEL_ID, $text);

        if (null !== $model) {
            return sprintf("'%s' names a model: name it in a provider descriptor of the catalog or in the demo data file, not in code.", rtrim($model, '.:'));
        }

        $host = $this->firstMatchOf(self::PROVIDER_HOST, $text);

        return null === $host ? null : sprintf("'%s' names a provider host: name it in a provider descriptor of the catalog, not in code.", $host);
    }

    private function firstMatchOf(string $pattern, string $text): ?string
    {
        return 1 === preg_match($pattern, $text, $match) ? $match[0] : null;
    }
}
