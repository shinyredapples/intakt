<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\JsonNode;
use Intakt\Gates\ProcessResult;

final readonly class CheckRun
{
    public function __construct(private ProcessResult $result)
    {
    }

    public function result(): ProcessResult
    {
        return $this->result;
    }

    public function exitCode(): int
    {
        return $this->result->exitCode;
    }

    public function passed(string $gate): bool
    {
        return '1' === $this->gateLine($gate)?->get('ok')->text();
    }

    public function failed(string $gate): bool
    {
        $line = $this->gateLine($gate);

        return $line instanceof JsonNode && '1' !== $line->get('ok')->text();
    }

    public function findingsOf(string $gate): string
    {
        $line = $this->gateLine($gate);

        return $line instanceof JsonNode ? implode("\n", array_map(static fn (JsonNode $finding): string => $finding->text(), $line->get('findings')->items())) : '';
    }

    public function omittedOf(string $gate): string
    {
        return $this->gateLine($gate)?->get('omitted')->text() ?? '';
    }

    public function noteOf(string $gate): string
    {
        return $this->gateLine($gate)?->get('note')->text() ?? '';
    }

    /**
     * @return list<string>
     */
    public function slowestFilesOf(string $gate): array
    {
        $line = $this->gateLine($gate);

        return $line instanceof JsonNode ? array_map(static fn (JsonNode $entry): string => $entry->get('file')->text(), $line->get('slowest')->items()) : [];
    }

    public function output(): string
    {
        return $this->result->output."\n".$this->result->errors;
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return array_values(array_filter(explode("\n", $this->result->output), static fn (string $line): bool => '' !== $line));
    }

    private function gateLine(string $gate): ?JsonNode
    {
        foreach ($this->lines() as $line) {
            $node = JsonNode::parse($line);

            if ($node instanceof JsonNode && $node->get('gate')->text() === $gate) {
                return $node;
            }
        }

        return null;
    }
}
