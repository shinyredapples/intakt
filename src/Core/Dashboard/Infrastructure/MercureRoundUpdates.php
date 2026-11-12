<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Twig\Environment;

final readonly class MercureRoundUpdates implements RoundUpdates
{
    public function __construct(
        private LiveUpdates $live,
        private Environment $twig,
        private LoggerInterface $logger,
    ) {
    }

    public static function topicOf(string $round): string
    {
        return 'review/rounds/'.$round;
    }

    #[Override]
    public function toolCalled(Uuid $round, string $reviewer, string $tool): void
    {
        $this->publish($round, RoundStream::ToolCalled, ['round' => $round->toRfc4122(), 'reviewer' => $reviewer, 'tool' => $tool]);
    }

    #[Override]
    public function textStreamed(Uuid $round, string $reviewer, string $text): void
    {
        $this->publish($round, RoundStream::TextStreamed, ['round' => $round->toRfc4122(), 'reviewer' => $reviewer, 'text' => $text]);
    }

    #[Override]
    public function roundChanged(Uuid $round, RoundProgress $progress): void
    {
        $this->publish($round, RoundStream::RoundChanged, ['round' => $round->toRfc4122(), 'progress' => $progress]);
    }

    #[Override]
    public function reviewerStopped(Uuid $round, string $reviewer): void
    {
        $this->publish($round, RoundStream::ReviewerStopped, ['round' => $round->toRfc4122(), 'reviewer' => $reviewer]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function publish(Uuid $round, RoundStream $stream, array $context): void
    {
        try {
            $turboStream = $this->twig->render($stream->template(), $context);
        } catch (Throwable $failure) {
            // Swallowed after a log, because a live update is best effort and must never change a verdict or a round.
            $this->logger->warning('A live round update was not rendered.', ['round' => $round->toRfc4122(), 'stream' => $stream->value, 'exception' => $failure]);

            return;
        }

        $this->live->publish(new LiveEvent(self::topicOf($round->toRfc4122()), $turboStream));
    }
}
