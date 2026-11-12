<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Closure;
use Override;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;

// Runs what a test chooses after each call is sent and before its answer is read, because that puts several reviewers' calls in flight together without any timing; the n-th call goes to the n-th platform, the last one answering the rest.
final class InFlightTogether implements PlatformInterface
{
    public int $calls = 0;

    /** @var list<PlatformInterface> */
    private readonly array $answers;

    /**
     * @param Closure(): void $meanwhile
     */
    public function __construct(private readonly Closure $meanwhile, PlatformInterface $first, PlatformInterface ...$then)
    {
        $this->answers = [$first, ...array_values($then)];
    }

    #[Override]
    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $sent = $this->answers[min($this->calls++, count($this->answers) - 1)]->invoke($model, $input, $options);
        ($this->meanwhile)();

        return $sent;
    }

    #[Override]
    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->answers[0]->getModelCatalog();
    }
}
