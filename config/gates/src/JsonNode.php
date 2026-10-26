<?php

declare(strict_types=1);

namespace Intakt\Gates;

use JsonException;

final readonly class JsonNode
{
    private function __construct(private mixed $value)
    {
    }

    public static function parse(string $json): ?self
    {
        try {
            return new self(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return null;
        }
    }

    public function get(string $key): self
    {
        if (!is_array($this->value) || !array_key_exists($key, $this->value)) {
            return new self(null);
        }

        return new self($this->value[$key]);
    }

    /**
     * @return list<self>
     */
    public function items(): array
    {
        if (!is_array($this->value)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $item): self => new self($item), $this->value));
    }

    /**
     * @return array<string, self>
     */
    public function entries(): array
    {
        if (!is_array($this->value)) {
            return [];
        }

        $entries = [];

        foreach ($this->value as $key => $item) {
            $entries[(string) $key] = new self($item);
        }

        return $entries;
    }

    public function text(): string
    {
        return is_scalar($this->value) ? (string) $this->value : '';
    }
}
