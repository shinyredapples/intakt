<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class DocblockRule
{
    private const string TOOL_PREFIX = '/^(phpstan|psalm)-/';
    private const array SHAPES = [
        'var' => 'variable', 'param' => 'parameter', 'param-out' => 'parameter',
        'return' => 'type', 'throws' => 'type', 'mixin' => 'type', 'extends' => 'type', 'implements' => 'type', 'use' => 'type',
        'require-extends' => 'type', 'require-implements' => 'type', 'self-out' => 'type', 'this-out' => 'type',
        'template' => 'template', 'template-covariant' => 'template', 'template-contravariant' => 'template',
        'type' => 'alias', 'import-type' => 'import',
        'assert' => 'assertion', 'assert-if-true' => 'assertion', 'assert-if-false' => 'assertion',
        'pure' => 'empty', 'impure' => 'empty',
        'method' => 'method', 'property' => 'property', 'property-read' => 'property', 'property-write' => 'property',
        'deprecated' => 'reason',
    ];
    private const array REMAINDERS = [
        'type' => '/^$/',
        'variable' => '/^(\$\w+)?$/',
        'parameter' => '/^&?(\.\.\.)?\$\w+$/',
        'property' => '/^\$\w+$/',
        'assertion' => '/^\$[\w>-]+$/',
    ];
    private const array WHOLE_BODIES = [
        'empty' => '/^$/',
        'reason' => '/\S/',
        'import' => '/^\w+\s+from\s+[\w\\\\]+(\s+as\s+\w+)?$/',
        'method' => '/^(static\s+)?(\S+\s+)?\w+\([^)]*\)$/',
    ];
    private const string TEMPLATE = '/^\w+(?:\s+(?:of|as)\s+(.+?))?(?:\s*=\s*(.+))?$/';
    private const string ALIAS = '/^\w+\s*=?\s*(.+)$/';

    public function __construct(private TypeText $types = new TypeText())
    {
    }

    public function problem(string $docblock): ?string
    {
        if (SuppressionDirective::appearsIn($docblock)) {
            return 'suppression directive, fix the code instead';
        }

        $tags = $this->tagsOf($docblock);

        if (null === $tags) {
            return 'docblock with prose, only type tags are allowed';
        }

        if ([] === $tags) {
            return 'empty docblock';
        }

        foreach ($tags as [$tag, $body]) {
            $problem = $this->tagProblem($tag, $body);

            if (null !== $problem) {
                return $problem;
            }
        }

        return null;
    }

    /**
     * @return list<array{string, string}>|null
     */
    private function tagsOf(string $docblock): ?array
    {
        $tags = [];

        foreach ($this->linesOf($docblock) as $line) {
            if (1 === preg_match('/^@([\w-]+)\s*(.*)$/', $line, $match)) {
                $tags[] = [$match[1], $match[2]];
            } elseif ('' !== $line && [] === $tags) {
                return null;
            } elseif ('' !== $line) {
                $tags = $this->withContinuation($tags, $line);
            }
        }

        return $tags;
    }

    private function tagProblem(string $tag, string $body): ?string
    {
        $shape = self::SHAPES[preg_replace(self::TOOL_PREFIX, '', $tag) ?? $tag] ?? null;

        if (null === $shape) {
            return '@'.$tag.' is not a type tag';
        }

        return $this->fits($shape, trim($body)) ? null : '@'.$tag.' carries prose or lacks its type';
    }

    private function fits(string $shape, string $body): bool
    {
        return match (true) {
            isset(self::WHOLE_BODIES[$shape]) => 1 === preg_match(self::WHOLE_BODIES[$shape], $body),
            'template' === $shape => 1 === preg_match(self::TEMPLATE, $body, $match) && $this->isTypeOrNothing($match[1] ?? '') && $this->isTypeOrNothing($match[2] ?? ''),
            'alias' === $shape => 1 === preg_match(self::ALIAS, $body, $match) && $this->typeThen($match[1], '/^$/'),
            default => $this->typeThen($body, self::REMAINDERS[$shape] ?? '/^$/'),
        };
    }

    private function isTypeOrNothing(string $text): bool
    {
        return '' === trim($text) || $this->typeThen($text, '/^$/');
    }

    private function typeThen(string $body, string $remainder): bool
    {
        $rest = $this->types->remainderAfterType($body);

        return null !== $rest && 1 === preg_match($remainder, $rest);
    }

    /**
     * @return list<string>
     */
    private function linesOf(string $docblock): array
    {
        $inner = preg_replace(['~^/\*\*~', '~\*/$~'], '', trim($docblock));
        $lines = preg_split('/\R/', (string) $inner);

        return array_map(static fn (string $line): string => trim((string) preg_replace('/^\s*\*\s?/', '', $line)), false === $lines ? [] : $lines);
    }

    /**
     * @param non-empty-list<array{string, string}> $tags
     *
     * @return non-empty-list<array{string, string}>
     */
    private function withContinuation(array $tags, string $line): array
    {
        [$tag, $body] = array_pop($tags);
        $tags[] = [$tag, $body.' '.$line];

        return $tags;
    }
}
