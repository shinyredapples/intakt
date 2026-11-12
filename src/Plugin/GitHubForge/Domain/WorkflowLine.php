<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class WorkflowLine
{
    // Script text, a comment and a blank line have neither a node nor a reason: only their expressions are checked, since a quote left open above may make a comment part of a value.
    private function __construct(
        public int $number,
        public string $text,
        public ?WorkflowNode $node,
        public ?string $unreadable,
    ) {
    }

    public static function node(int $number, string $text, WorkflowNode $node): self
    {
        return new self($number, $text, $node, null);
    }

    public static function textOnly(int $number, string $text): self
    {
        return new self($number, $text, null, null);
    }

    public static function unreadable(int $number, string $text, string $reason): self
    {
        return new self($number, $text, null, $reason);
    }
}
