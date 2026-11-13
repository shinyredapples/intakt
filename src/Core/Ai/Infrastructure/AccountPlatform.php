<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RetryableExceptionInterface;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;

// Excluded from the container because AccountFailoverPlatform builds one per call around an account's own platform.
#[Exclude]
final readonly class AccountPlatform implements PlatformInterface
{
    public function __construct(
        private Account $account,
        private PlatformInterface $platform,
        private AccountCooldowns $cooldowns,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private SendFailures $failures,
    ) {
    }

    #[Override]
    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $sentAt = $this->clock->now();

        try {
            $result = $this->platform->invoke($model, $input, $options);
        } catch (Throwable $failure) {
            $this->failed($failure);

            throw $failure;
        }

        $result->getMetadata()->add(Account::METADATA, $this->account->key());
        // A provider's limit arrives when the answer is read, not when the call is sent, so the cooldown hangs on the result.
        $result->onError($this->failed(...));
        // A limit that arrives while the stream is read never reaches onError, so the stream reports it too.
        $result->onConvert(fn (ResultInterface $answer): ResultInterface => $this->followAnswer($answer, $sentAt));

        return $result;
    }

    #[Override]
    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->platform->getModelCatalog();
    }

    // An answer the account gave clears its reported error, a streamed one only once it was read whole.
    private function followAnswer(ResultInterface $result, DateTimeImmutable $sentAt): ResultInterface
    {
        if (!$result instanceof StreamResult) {
            $this->cooldowns->answered($this->account, $sentAt);

            return $result;
        }

        $result->addListener(new AccountStreamFailures($this->failed(...)));
        $result->addListener(new AccountStreamAnswered(fn () => $this->cooldowns->answered($this->account, $sentAt)));

        return $result;
    }

    private function failed(Throwable $failure): void
    {
        // Once the answer has started, StreamedAnswer wraps the provider's exception, so the cooldown looks through to it.
        $cause = $failure instanceof IncompleteStreamException ? $failure->getPrevious() ?? $failure : $failure;

        if ($this->failures->record($this->account, $cause)) {
            $this->coolDownAfter($cause);
        }
    }

    // Matched by the exception type only, because the provider's text can change (plan T5); logged whole, because the log redacts a key the text echoes.
    private function coolDownAfter(Throwable $failure): void
    {
        $this->logger->warning('Account {account} failed.', ['account' => $this->account->key(), 'exception' => $failure]);

        match (true) {
            $failure instanceof RateLimitExceededException => $this->cooldowns->coolDown($this->account, $failure->getRetryAfter()),
            // A revoked or wrong key will not work on the next call either, so the account is out like a limited one and the call goes elsewhere; remembered as a rejection so no call waits for it.
            $failure instanceof AuthenticationException => $this->cooldowns->coolDownRejected($this->account),
            $failure instanceof RetryableExceptionInterface => $this->cooldowns->coolDown($this->account, null),
            default => null,
        };
    }
}
