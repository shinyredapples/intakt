<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

use Error;
use Exception;
use InvalidArgumentException;
use LogicException;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use RuntimeException;
use Throwable;

/**
 * @implements Rule<FileNode>
 */
final readonly class NarrowCatchRule implements Rule
{
    private const array ROOTS = [Exception::class, Error::class, RuntimeException::class, LogicException::class, InvalidArgumentException::class];

    // a one-line comment that names what the boundary protects, so a bare "Boundary:" does not count
    private const string BOUNDARY = '~^(?://|#)\s*Boundary:\s*\S~';

    private const string SOURCE = '/src/';

    public function __construct(
        private string $root,
    ) {
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!str_starts_with($scope->getFile(), $this->root.self::SOURCE)) {
            return [];
        }

        // PHPStan skips the body of a trait no class uses and analyzes a used trait in the scope of the class that uses it, so the file's own tree is searched to keep the folder boundary exact
        $tries = new NodeFinder()->findInstanceOf($node->getNodes(), TryCatch::class);

        if ([] === $tries) {
            return [];
        }

        $source = (string) file_get_contents($scope->getFile());

        return array_merge(...array_map(fn (TryCatch $try): array => $this->findingsIn($try, $source), $tries));
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function findingsIn(TryCatch $try, string $source): array
    {
        $findings = [];
        $before = $try->stmts;

        foreach ($try->catches as $catch) {
            $findings = [...$findings, ...$this->findingsOf($catch, $before, $source)];
            $before = $catch->stmts;
        }

        return $findings;
    }

    /**
     * @param array<Stmt> $before
     *
     * @return list<IdentifierRuleError>
     */
    private function findingsOf(Catch_ $catch, array $before, string $source): array
    {
        $problems = array_map(
            fn (string $type): ?string => 0 === strcasecmp($type, Throwable::class)
                ? $this->throwableProblem($catch, $before, $source)
                : sprintf('A catch of %s: name the failures the code expects (a library\'s own exception base or a domain exception); only %s may be caught, and only at a failure boundary.', $type, Throwable::class),
            $this->genericTypesOf($catch),
        );

        return array_values(array_map(fn (string $problem): IdentifierRuleError => $this->finding($catch, $problem), array_filter($problems, is_string(...))));
    }

    /**
     * @param array<Stmt> $before
     */
    private function throwableProblem(Catch_ $catch, array $before, string $source): ?string
    {
        if (!$this->hasBoundaryCommentAbove($catch, $before, $source)) {
            return sprintf('A catch of %s outside a failure boundary: name the failures the code expects, or, where it rethrows or records the failure, put a "// Boundary: <what it protects>" line directly above the catch.', Throwable::class);
        }

        if (!$this->rethrowsOrRecords($catch)) {
            return sprintf('A catch of %s at a failure boundary that neither rethrows nor records the failure: throw, or use the caught failure (log, ledger, a typed outcome) before the unit of work ends.', Throwable::class);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function genericTypesOf(Catch_ $catch): array
    {
        $names = array_map(static fn (Name $type): string => ltrim($type->toString(), '\\'), $catch->types);

        return array_values(array_filter($names, $this->isGeneric(...)));
    }

    // in any case, because PHP resolves class names case-insensitively and \runtimeexception catches the same class
    private function isGeneric(string $name): bool
    {
        return in_array(strtolower($name), array_map(strtolower(...), [Throwable::class, ...self::ROOTS]), true);
    }

    private function finding(Catch_ $catch, string $message): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('intakt.narrowCatch')
            ->line($catch->getStartLine())
            ->build();
    }

    // with "} catch" the comment above the catch closes the block before it and becomes a trailing Nop there; otherwise it belongs to the catch itself
    /**
     * @param array<Stmt> $before
     */
    private function hasBoundaryCommentAbove(Catch_ $catch, array $before, string $source): bool
    {
        $last = array_last($before);
        $comments = [...$catch->getComments(), ...($last instanceof Nop ? $last->getComments() : [])];

        return array_any(
            $comments,
            fn (Comment $comment): bool => $comment->getEndLine() === $catch->getStartLine() - 1
                && $this->startsItsLine($comment, $source)
                && 1 === preg_match(self::BOUNDARY, $comment->getText()),
        );
    }

    // because a comment trailing code on its line annotates that code, not the catch below it
    private function startsItsLine(Comment $comment, string $source): bool
    {
        return 0 === preg_match('/\S[^\r\n]*\z/', substr($source, 0, $comment->getStartFilePos()));
    }

    private function rethrowsOrRecords(Catch_ $catch): bool
    {
        $name = $catch->var?->name;

        return RunningCode::anyIn(
            $catch->stmts,
            fn (Node $node): bool => $node instanceof Throw_
                || ($node instanceof Return_ && $this->returnsAnOutcome($node))
                || (is_string($name) && $node instanceof Variable && $node->name === $name),
        );
    }

    // a returned value hands the failure to the caller as a typed outcome, so only a bare return or null drops it
    private function returnsAnOutcome(Return_ $return): bool
    {
        return $return->expr instanceof Expr
            && (!$return->expr instanceof ConstFetch || 'null' !== $return->expr->name->toLowerString());
    }
}
