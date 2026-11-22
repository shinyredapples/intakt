<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

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

    // Symfony AI's list form of an input, such as several texts in one call.
    public static function ofList(mixed ...$parts): self
    {
        return new self($parts, []);
    }

    // An option already set wins, so a caller's own value is kept over a default added later.
    public function withDefaults(mixed ...$options): self
    {
        return clone ($this, ['options' => $this->options + $options]);
    }

    #[NoDiscard('the answer is only in the result')]
    public function invokeOn(PlatformInterface $platform, string $model): DeferredResult
    {
        return $platform->invoke($model, $this->input, $this->options);
    }
}
