<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Override;
use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\CompleteEvent;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;
use Symfony\AI\Platform\Result\Stream\ErrorEvent;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Needed because Symfony AI adds up every usage event of a stream, while a provider repeats a figure it already reported, so the sum would count the call more than once; excluded from the container because one is built per stream.
#[Exclude]
final class FinalStreamedUsage extends AbstractStreamListener
{
    private const string TOKEN_USAGE = 'token_usage';

    private ?TokenUsageInterface $final = null;

    #[Override]
    public function onDelta(DeltaEvent $event): void
    {
        $delta = $event->getDelta();

        if ($delta instanceof TokenUsageInterface) {
            $this->final = $this->final instanceof TokenUsageInterface ? $this->updated($this->final, $delta) : $delta;
        }
    }

    #[Override]
    public function onComplete(CompleteEvent $event): void
    {
        $this->settle($event->getResult());
    }

    // Also on a cut-off stream, because the tokens it reported were spent and the vendor leaves them unsettled.
    #[Override]
    public function onError(ErrorEvent $event): void
    {
        $this->settle($event->getResult());
    }

    // A figure the later event leaves out keeps its earlier reading, because Anthropic reports the input and cache tokens only on its first event and the output tokens only on its last.
    private function updated(TokenUsageInterface $earlier, TokenUsageInterface $later): TokenUsage
    {
        return new TokenUsage(
            $later->getPromptTokens() ?? $earlier->getPromptTokens(),
            $later->getCompletionTokens() ?? $earlier->getCompletionTokens(),
            $later->getThinkingTokens() ?? $earlier->getThinkingTokens(),
            $later->getToolTokens() ?? $earlier->getToolTokens(),
            $later->getCachedTokens() ?? $earlier->getCachedTokens(),
            $later->getCacheCreationTokens() ?? $earlier->getCacheCreationTokens(),
            $later->getCacheReadTokens() ?? $earlier->getCacheReadTokens(),
            $later->getRemainingTokens() ?? $earlier->getRemainingTokens(),
            $later->getRemainingTokensMinute() ?? $earlier->getRemainingTokensMinute(),
            $later->getRemainingTokensMonth() ?? $earlier->getRemainingTokensMonth(),
            $later->getTotalTokens() ?? $earlier->getTotalTokens(),
            $later->getModel() ?? $earlier->getModel(),
        );
    }

    private function settle(StreamResult $stream): void
    {
        if (!$this->final instanceof TokenUsageInterface) {
            return;
        }

        $stream->getMetadata()->remove(self::TOKEN_USAGE);
        $stream->getMetadata()->add(self::TOKEN_USAGE, $this->final);
    }
}
