<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class WorkflowLine
{
    // Script text has neither a node nor a reason, since GitHub evaluates only its expressions.
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

    public static function scriptText(int $number, string $text): self
    {
        return new self($number, $text, null, null);
    }

    public static function unreadable(int $number, string $text, string $reason): self
    {
        return new self($number, $text, null, $reason);
    }
}
