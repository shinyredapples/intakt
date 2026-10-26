<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class DocblockRule
{
    private const string TYPE_TAGS = '/^((phpstan|psalm)-)?(var|param|return|throws|extends|implements|use|type|import-type|template(-covariant|-contravariant)?|assert(-if-true|-if-false)?)$/';
    private const string TEMPLATE_BODY = '/^\w+(\s+(of|as)\s+\S.*|\s*=.+)?$/';
    private const string TOOL_TAGS = '/^(phpstan|psalm)-/';

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

    private function tagProblem(string $tag, string $body): ?string
    {
        if (1 !== preg_match(self::TYPE_TAGS, $tag)) {
            return '@'.$tag.' is not a type tag';
        }

        if (1 === preg_match(self::TOOL_TAGS, $tag)) {
            return null;
        }

        return $this->bodyProblem($tag, $body);
    }

    private function bodyProblem(string $tag, string $body): ?string
    {
        if (str_starts_with($tag, 'template')) {
            return 1 === preg_match(self::TEMPLATE_BODY, $body) ? null : '@'.$tag.' carries prose';
        }

        $rest = $this->types->remainderAfterType($body);

        if (null === $rest) {
            return '@'.$tag.' without a type';
        }

        return 1 === preg_match($this->allowedRemainder($tag), $rest) ? null : '@'.$tag.' carries prose after its type';
    }

    private function allowedRemainder(string $tag): string
    {
        return match (true) {
            'var' === $tag => '/^(\$\w+)?$/',
            'param' === $tag => '/^(&\s*)?(\.\.\.)?\$\w+$/',
            default => '/^$/',
        };
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

    /**
     * @return list<string>
     */
    private function linesOf(string $docblock): array
    {
        $inner = preg_replace(['~^/\*\*~', '~\*/$~'], '', trim($docblock));
        $lines = preg_split('/\R/', (string) $inner);

        return array_map(static fn (string $line): string => trim((string) preg_replace('/^\s*\*\s?/', '', $line)), false === $lines ? [] : $lines);
    }
}
