<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class WorkflowValue
{
    private const string BLOCK_SCALAR = '/^[|>][+-]?$/';
    private const string FLOW_SEQUENCE = '/^\[[^\[\]{}]*\]$/';
    // Anchors, aliases, tags, flow collections and reserved indicators start with one of these, so a value starting so cannot be read as plain text.
    private const array INDICATORS = ['&', '*', '!', '%', '@', '`', '|', '>', '?', '{', '}', '[', ']', ','];

    public function __construct(
        public string $raw,
    ) {
    }

    public function startsBlockScalar(): bool
    {
        return preg_match(self::BLOCK_SCALAR, $this->raw) === 1;
    }

    public function isReadable(): bool
    {
        return match (true) {
            in_array($this->raw, ['', '{}'], true), $this->startsBlockScalar() => true,
            str_starts_with($this->raw, '[') => $this->isReadableFlowSequence(),
            default => $this->isReadableScalar($this->raw),
        };
    }

    public function text(): string
    {
        return $this->unquoted($this->raw);
    }

    /** @return list<string> */
    public function listed(): array
    {
        if ($this->raw === '') {
            return [];
        }
        if (!str_starts_with($this->raw, '[')) {
            return [$this->text()];
        }

        return array_map($this->unquoted(...), $this->items());
    }

    private function isReadableFlowSequence(): bool
    {
        return preg_match(self::FLOW_SEQUENCE, $this->raw) === 1 && array_all($this->items(), $this->isReadableScalar(...));
    }

    private function isReadableScalar(string $value): bool
    {
        $first = $value[0] ?? '';
        if (in_array($first, self::INDICATORS, true)) {
            return false;
        }

        return !in_array($first, ['"', "'"], true) || (strlen($value) > 1 && str_ends_with($value, $first));
    }

    // Split at every comma, because a comma inside a quoted item then leaves two unclosed quotes, which the scan refuses.
    /** @return list<string> */
    private function items(): array
    {
        $items = array_map(trim(...), explode(',', substr($this->raw, 1, -1)));

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    private function unquoted(string $value): string
    {
        $quote = $value[0] ?? '';
        if (in_array($quote, ['"', "'"], true) && strlen($value) > 1 && str_ends_with($value, $quote)) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
