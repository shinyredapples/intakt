<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Override;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The CLI answers in one piece however the call is made, because the bridge's stream path skips what CompletedCodexTurn and FinishedProcessResult read from the finished process (a named cut-off, the usage of a turn without an answer, the tools the CLI ran itself); streaming the CLIs is their own task (plan T33, T39). Excluded from the container because the platform factories build one per platform.
#[Exclude]
final readonly class UnstreamedCliConverter implements ResultConverterInterface
{
    private const string STREAM = 'stream';

    public function __construct(
        private ResultConverterInterface $inner,
    ) {
    }

    #[Override]
    public function supports(Model $model): bool
    {
        return $this->inner->supports($model);
    }

    #[Override]
    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        return $this->inner->convert($result, self::unstreamed($options));
    }

    // Wrapped too, because the bridge's extractor reads nothing when the call asked for a stream.
    #[Override]
    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        $extractor = $this->inner->getTokenUsageExtractor();

        return $extractor instanceof TokenUsageExtractorInterface ? new UnstreamedCliUsage($extractor) : null;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function unstreamed(array $options): array
    {
        unset($options[self::STREAM]);

        return $options;
    }
}
