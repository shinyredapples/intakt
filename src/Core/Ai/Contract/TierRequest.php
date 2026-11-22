<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use NoDiscard;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;

// Sends itself, because its input and options are Symfony AI's untyped forms and would otherwise cross the module as arrays.
final readonly class TierRequest
{
    /** @var array<mixed>|string|object */
    private array|string|object $input;

    /** @var array<string, mixed> */
    private array $options;

    public function __construct(string|object $input)
    {
        $this->input = $input;
        $this->options = [];
    }

    // Symfony AI's list form of an input, such as several texts in one call.
    public static function ofList(mixed ...$parts): self
    {
        return clone(new self(''), ['input' => $parts]);
    }

    // An option already set wins, so a caller's own value is kept over a default added later.
    public function withDefaults(mixed ...$options): self
    {
        return clone($this, ['options' => $this->options + $options]);
    }

    #[NoDiscard('the answer is only in the result')]
    public function invokeOn(PlatformInterface $platform, string $model): DeferredResult
    {
        return $platform->invoke($model, $this->input, $this->options);
    }
}
