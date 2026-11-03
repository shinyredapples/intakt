<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Twig\Environment;

// Through the hub's endpoint, not mercure_publish(), because consumers and the console have no FrankenPHP function; one path serves every process.
final readonly class MercureRoundUpdates implements RoundUpdates
{
    public function __construct(
        private HubInterface $hub,
        private Environment $twig,
        private LoggerInterface $logger,
    ) {
    }

    public static function topicOf(Uuid $round): string
    {
        return 'review/rounds/'.$round->toRfc4122();
    }

    #[Override]
    public function toolCalled(Uuid $round, string $reviewer, string $tool): void
    {
        $this->publish($round, 'tool_called', ['round' => $round->toRfc4122(), 'reviewer' => $reviewer, 'tool' => $tool]);
    }

    #[Override]
    public function textStreamed(Uuid $round, string $reviewer, string $text): void
    {
        $this->publish($round, 'text_streamed', ['round' => $round->toRfc4122(), 'reviewer' => $reviewer, 'text' => $text]);
    }

    #[Override]
    public function reviewerStopped(Uuid $round, string $reviewer): void
    {
        $this->publish($round, 'reviewer_stopped', ['round' => $round->toRfc4122(), 'reviewer' => $reviewer]);
    }

    #[Override]
    public function roundChanged(Uuid $round, RoundProgress $progress): void
    {
        $this->publish($round, 'round_changed', ['round' => $round->toRfc4122(), 'progress' => $progress]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function publish(Uuid $round, string $stream, array $context): void
    {
        try {
            $data = $this->twig->render(sprintf('dashboard/stream/%s.stream.html.twig', $stream), $context);
            // Private, because the text is model output about private code; only a subscriber the dashboard authorizes may read it.
            $this->hub->publish(new Update(self::topicOf($round), $data, true));
        } catch (Throwable $failure) {
            // Swallowed after a log, because a live update is best effort and must never change a verdict or a round; the class only, because a transport message can echo a token.
            $this->logger->warning('A live round update was not published.', ['stream' => $stream, 'exception' => $failure::class]);
        }
    }
}
