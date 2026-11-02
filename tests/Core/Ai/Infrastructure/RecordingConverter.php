<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Override;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

// Records the options a converter decorator passes on, because the bridge's own converter cannot say what it was given.
final class RecordingConverter implements ResultConverterInterface
{
    /**
     * @var array<string, mixed>
     */
    public array $options = [];

    #[Override]
    public function supports(Model $model): bool
    {
        return true;
    }

    #[Override]
    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        $this->options = $options;

        return new TextResult('');
    }

    #[Override]
    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return null;
    }
}
