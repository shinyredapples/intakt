<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Namespace_;
use PHPStan\Parser\Parser;
use PHPStan\Parser\ParserErrorsException;

final readonly class FileScopeDeprecation
{
    private const string FUNCTION = 'trigger_deprecation';

    public function __construct(private Parser $parser)
    {
    }

    public function triggeredBy(?string $file): bool
    {
        if (null === $file || !is_file($file)) {
            return false;
        }

        try {
            $statements = $this->parser->parseFile($file);
        } catch (ParserErrorsException) {
            return false;
        }

        return array_any($this->topLevel($statements), $this->isTrigger(...));
    }

    /**
     * @param array<Node> $statements
     *
     * @return list<Node>
     */
    private function topLevel(array $statements): array
    {
        $inNamespaces = array_map(static fn (Node $node): array => $node instanceof Namespace_ ? $node->stmts : [], $statements);

        return array_values([...$statements, ...array_merge([], ...$inNamespaces)]);
    }

    private function isTrigger(Node $statement): bool
    {
        // a call inside a method or a condition runs only when someone asks for it, so it deprecates nothing at load time
        return $statement instanceof Expression
            && $statement->expr instanceof FuncCall
            && $statement->expr->name instanceof Name
            && self::FUNCTION === $statement->expr->name->getLast();
    }
}
