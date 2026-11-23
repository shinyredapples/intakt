<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Closure;
use Override;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;

// Reads the account's own answer, so its cooldown and metadata stay as they are; excluded from the container because AccountFailoverPlatform builds one per call.
#[Exclude]
final readonly class TypedRejectionConverter implements ResultConverterInterface
{
    private const string TOKEN_USAGE = 'token_usage';

    /**
     * @param Closure(AuthenticationException|RateLimitExceededException): Throwable $typed
     */
    public function __construct(
        private DeferredResult $answer,
        private Closure $typed,
    ) {
    }

    #[Override]
    public function supports(Model $model): bool
    {
        return $this->answer->getResultConverter()->supports($model);
    }

    #[Override]
    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        try {
            return $this->answer->getResult();
        } catch (AuthenticationException|RateLimitExceededException $failure) {
            throw ($this->typed)($failure);
        }
    }

    // None once the account's answer carries its usage, because the result read above already holds it and DeferredResult would add it a second time; an answer that failed to map carries none, so its usage is still read from the raw answer.
    #[Override]
    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        if ($this->answer->getMetadata()->has(self::TOKEN_USAGE)) {
            return null;
        }

        return $this->answer->getResultConverter()->getTokenUsageExtractor();
    }
}
