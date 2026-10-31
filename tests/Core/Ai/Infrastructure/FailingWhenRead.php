<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Override;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\Test\MockModelCatalog;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Throwable;

// A provider that accepts the call and fails when its answer is read, as an HTTP provider reports a 429 (Symfony AI's own mock platform fails as the call is sent).
final class FailingWhenRead implements PlatformInterface, ResultConverterInterface
{
    public int $calls = 0;

    public function __construct(private readonly Throwable $failure)
    {
    }

    #[Override]
    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        ++$this->calls;

        return new DeferredResult($this, new InMemoryRawResult());
    }

    #[Override]
    public function getModelCatalog(): ModelCatalogInterface
    {
        return new MockModelCatalog();
    }

    #[Override]
    public function supports(Model $model): bool
    {
        return true;
    }

    #[Override]
    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        throw $this->failure;
    }

    #[Override]
    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return null;
    }
}
