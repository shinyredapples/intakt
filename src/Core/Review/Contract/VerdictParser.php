<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Closure;
use JsonException;
use NoDiscard;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Exception\ValidationException;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

final readonly class VerdictParser
{
    /**
     * @throws VerdictUnreadable
     */
    #[NoDiscard('a verdict that is not read approves nothing')]
    public function parse(DeferredResult $result): Verdict
    {
        return $this->parseWith(static fn (): Verdict => $result->asObject(Verdict::class));
    }

    /**
     * @param Closure(): Verdict $read
     *
     * @throws VerdictUnreadable
     */
    #[NoDiscard('a verdict that is not read approves nothing')]
    public function parseWith(Closure $read): Verdict
    {
        try {
            return $read();
        } catch (ValidationException $exception) {
            throw VerdictUnreadable::because($this->firstViolation($exception));
        } catch (RuntimeException $exception) {
            throw VerdictUnreadable::because($this->mappingFailure($exception));
        }
    }

    private function firstViolation(ValidationException $exception): string
    {
        /** @var ConstraintViolationListInterface $violations */
        $violations = $exception->getViolations();
        /** @var ConstraintViolationInterface $violation */
        $violation = $violations->get(0);

        return $violation->getPropertyPath().': '.$violation->getMessage();
    }

    /**
     * @throws RuntimeException
     */
    private function mappingFailure(RuntimeException $exception): string
    {
        $cause = $exception->getPrevious();
        if ($exception instanceof UnexpectedResultTypeException || $cause instanceof JsonException || $cause instanceof ExceptionInterface) {
            return trim($exception->getMessage().' '.$cause?->getMessage());
        }

        // A failed call is not the reviewer's answer, so it stays an error instead of becoming changes.
        throw $exception;
    }
}
