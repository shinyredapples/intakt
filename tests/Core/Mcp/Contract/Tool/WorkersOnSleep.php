<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract\Tool;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRoundHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Override;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

// The waiting review's clock: each sleep hands what is queued now to the workers, so a started round is stored and judged within the request and no test sleeps for real; one batch per sleep, so every step of the round is reported.
final readonly class WorkersOnSleep implements ClockInterface
{
    public function __construct(
        #[Autowire(service: 'messenger.transport.async')]
        private InMemoryTransport $queue,
        #[Autowire(service: 'intakt.test.mcp.reviewer_worker')]
        private ReviewWithReviewerHandler $reviewer,
        #[Autowire(service: 'intakt.test.mcp.judge_worker')]
        private JudgeRoundHandler $judge,
        private MockClock $clock = new MockClock("2026-11-01 12:00:00", "UTC"),
    ) {
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    #[Override]
    public function sleep(float|int $seconds): void
    {
        foreach ($this->queue->get() as $envelope) {
            $this->queue->ack($envelope);
            $this->work($envelope->getMessage());
        }

        $this->clock->sleep($seconds);
    }

    #[Override]
    public function withTimeZone(DateTimeZone|string $timezone): static
    {
        return $this;
    }

    private function work(object $message): void
    {
        match (true) {
            $message instanceof ReviewWithReviewer => ($this->reviewer)($message),
            $message instanceof JudgeRound => ($this->judge)($message),
            default => null,
        };
    }
}
