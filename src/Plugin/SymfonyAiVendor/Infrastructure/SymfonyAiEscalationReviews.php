<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryProviderOut;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Plugin\Vendor\Contract\EscalationReviews;
use JsonException;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Exception\ValidationException;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

// An agent with no toolbox and no message store, because the escalation judges only the messages it is given.
#[AsAlias(EscalationReviews::class)]
final readonly class SymfonyAiEscalationReviews implements EscalationReviews
{
    // The schema sent to the model requires every field and forbids others, and Symfony AI's own mapping allows extra fields, so the answer is read again under the schema's rules.
    private const array STRICT = [
        AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false,
        AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true,
    ];

    public function __construct(
        private TierInvoker $tiers,
        private UsageLedger $usage,
        private VendorAccountLimit $accounts,
        private SerializerInterface $serializer,
        private LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function escalate(string $project, Uuid $round, MessageBag $messages): ?EscalationVerdict
    {
        $answering = $this->answering($project);

        return $this->accounts->run($answering->provider, fn (): ?EscalationVerdict => $this->verdictOn($answering, $this->usage->reserve($project, new AnsweringTier(self::TIER, $answering), $round), $messages));
    }

    private function answering(string $project): ResolvedModel
    {
        try {
            return $this->tiers->answering($project, self::TIER);
        } catch (EveryAccountCoolingDown|EveryProviderOut $out) {
            throw TypedProviderFailure::of($out);
        }
    }

    private function verdictOn(ResolvedModel $answering, UsageReservation $reservation, MessageBag $messages): ?EscalationVerdict
    {
        $platform = new TierRoutedPlatform($this->tiers, $answering);
        $agent = new Agent($platform, $answering->model, name: 'escalation');

        try {
            $verdict = $this->answerIn($agent->call($messages, [PlatformSubscriber::RESPONSE_FORMAT => EscalationVerdict::class]));
            // Boundary: leaves the call unsettled and rethrows it typed, because any failure of the call must reach the queue as a vendor failure, which tries the escalation again.
        } catch (Throwable $failure) {
            $this->leaveUnsettled($reservation, $failure, $platform);

            throw TypedProviderFailure::of($failure);
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

        return $answer instanceof EscalationVerdict && $this->fitsTheSchema($execution->getResult()) ? $answer : null;
    }

    // Boundary: a ledger that cannot take the row is logged by class only, never thrown, because the provider's error carries the queue's delay and its chain may echo part of a key.
    private function leaveUnsettled(UsageReservation $reservation, Throwable $failure, TierRoutedPlatform $platform): void
    {
        try {
            $this->usage->leaveUnsettled($reservation, $failure, ...$platform->rounds());
        } catch (Throwable $unrecorded) {
            $this->logger->error('The failed escalation call could not be left unsettled.', ['call' => $reservation->call, 'failure' => $unrecorded::class]);
        }
    }

    private function fitsTheSchema(ResultInterface $result): bool
    {
        if (!$result instanceof ObjectResult || null === $result->getText()) {
            return false;
        }

        try {
            $this->serializer->deserialize($result->getText(), EscalationVerdict::class, JsonEncoder::FORMAT, self::STRICT);
        } catch (ExceptionInterface) {
            return false;
        }

        return true;
    }
}
