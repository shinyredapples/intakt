<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use LogicException;
use NoDiscard;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;

// Sends itself, because its input and options are Symfony AI's untyped forms and would otherwise cross the module as arrays.
final readonly class TierRequest
{
    /**
     * @param array<mixed>|string|object $input
     * @param array<string, mixed>       $options
     */
    private function __construct(
        private array|string|object $input,
        private array $options,
    ) {
    }

    public static function of(string|object $input): self
    {
        return new self($input, []);
    }

    // Built one part at a time, so an array input keeps its keys and their order without an array crossing the contract.
    public static function ofParts(): self
    {
        return new self([], []);
    }

    public function withPart(int|string $key, mixed $part): self
    {
        if (!is_array($this->input)) {
            throw new LogicException('Only a request made with ofParts() takes parts.');
        }

        // Assigned, not spread: spreading would renumber integer keys.
        $input = $this->input;
        $input[$key] = $part;

        return clone ($this, ['input' => $input]);
    }

    // An option already set wins, so a caller's own value is kept over a default added later.
    public function withDefaults(mixed ...$options): self
    {
        /** @var array<string, mixed> $named */
        $named = $options;

        return clone ($this, ['options' => $this->options + $named]);
    }

    /**
     * @param non-empty-string $model
     */
    #[NoDiscard('the answer is only in the result')]
    public function invokeOn(PlatformInterface $platform, string $model): DeferredResult
    {
        return $platform->invoke($model, $this->input, $this->options);
    }
}
