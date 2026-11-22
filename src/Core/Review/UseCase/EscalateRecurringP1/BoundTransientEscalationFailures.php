<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\EscalateRecurringP1;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageMiddleware;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Throwable;

// The worker retries the envelope of the HandlerFailedException, so the count travels with the message; the transport's own retry strategy, asked as if no forced retry came before, decides the bound and the delays, so they are never typed a second time.
#[AsMessageMiddleware(bus: 'command.bus')]
final readonly class BoundTransientEscalationFailures implements MiddlewareInterface
{
    public function __construct(
        #[Autowire(service: 'messenger.retry_strategy_locator')]
        private ContainerInterface $strategies,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (HandlerFailedException $failed) {
            throw $this->counted($failed);
        }
    }

    private function counted(HandlerFailedException $failed): HandlerFailedException
    {
        $envelope = $failed->getEnvelope();
        $received = $envelope->last(ReceivedStamp::class);
        $transient = array_filter($failed->getWrappedExceptions(EscalationLimited::class), static fn (EscalationLimited $limited): bool => !$limited->forceRetry());

        if (!$envelope->getMessage() instanceof EscalateRecurringP1 || !$received instanceof ReceivedStamp || [] === $transient || !$this->strategies->has($received->getTransportName())) {
            return $failed;
        }

        $strategy = $this->strategies->get($received->getTransportName());
        assert($strategy instanceof RetryStrategyInterface);
        $earlier = $envelope->last(TransientEscalationFailures::class)->count ?? 0;
        $alone = new Envelope($envelope->getMessage(), [new RedeliveryStamp($earlier)]);
        $outcome = $strategy->isRetryable($alone)
            ? EscalationLimited::retriedIn($strategy->getWaitingTime($alone))
            : new UnrecoverableMessageHandlingException(sprintf('The provider failed the escalation review\'s call %d times; the escalation is parked.', $earlier + 1));

        return new HandlerFailedException(
            $envelope->withoutAll(TransientEscalationFailures::class)->with(new TransientEscalationFailures($earlier + 1)),
            array_replace($failed->getWrappedExceptions(), array_map(static fn (): Throwable => $outcome, $transient)),
        );
    }
}
