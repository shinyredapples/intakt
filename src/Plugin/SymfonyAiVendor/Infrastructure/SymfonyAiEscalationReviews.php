<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Plugin\Vendor\Contract\EscalationReviews;
use JsonException;
use Override;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Exception\ValidationException;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

// An agent with no toolbox and no message store, because the escalation judges only the messages it is given.
#[AsAlias(EscalationReviews::class)]
final readonly class SymfonyAiEscalationReviews implements EscalationReviews
{
    public function __construct(
        private TierInvoker $tiers,
        private UsageLedger $usage,
        private VendorAccountLimit $accounts,
    ) {
    }

    #[Override]
    public function escalate(string $project, Uuid $round, MessageBag $messages): ?EscalationVerdict
    {
        $answering = $this->tiers->answering($project, self::TIER);

        return $this->accounts->run($answering->provider, fn (): ?EscalationVerdict => $this->verdictOn($answering, $this->usage->reserve($project, new AnsweringTier(self::TIER, $answering), $round), $messages));
    }

    private function verdictOn(ResolvedModel $answering, UsageReservation $reservation, MessageBag $messages): ?EscalationVerdict
    {
        $platform = new TierRoutedPlatform($this->tiers, $answering);
        $agent = new Agent($platform, $answering->model, name: 'escalation');

        try {
            $verdict = $this->answerIn($agent->call($messages, [PlatformSubscriber::RESPONSE_FORMAT => EscalationVerdict::class]));
            // Boundary: leaves the call unsettled and rethrows it unchanged, because any failure of the call must reach the queue, which tries the escalation again.
        } catch (Throwable $failure) {
            $this->usage->leaveUnsettled($reservation, $failure, ...$platform->rounds());

            throw $failure;
        }

        $this->usage->settle($reservation, ...$platform->rounds());

        return $verdict;
    }

    // Anything the Validator or the mapping refuses is no answer; a failed call is not the model's answer, so it stays an error.
    private function answerIn(ExecutionInterface $execution): ?EscalationVerdict
    {
        try {
            $answer = $execution->getContent();
        } catch (ValidationException) {
            return null;
        } catch (RuntimeException $exception) {
            $cause = $exception->getPrevious();
            if ($exception instanceof UnexpectedResultTypeException || $cause instanceof JsonException || $cause instanceof ExceptionInterface) {
                return null;
            }

            throw $exception;
        }

        return $answer instanceof EscalationVerdict ? $answer : null;
    }
}
