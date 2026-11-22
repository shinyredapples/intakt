<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Closure;
use JsonException;
use NoDiscard;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Exception\ValidationException;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

final readonly class VerdictParser
{
    private const string PROPERTY_PATH = '/^[a-z_]\w*(\[\d+\])*(\.[a-z_]\w*(\[\d+\])*)*$/i';

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
            throw $this->firstViolation($exception);
        } catch (RuntimeException $exception) {
            throw VerdictUnreadable::because($this->mappingFailure($exception));
        }
    }

    private function firstViolation(ValidationException $exception): VerdictUnreadable
    {
        /** @var ConstraintViolationListInterface $violations */
        $violations = $exception->getViolations();
        /** @var ConstraintViolationInterface $violation */
        $violation = $violations->get(0);
        $reason = $violation->getPropertyPath().': '.$violation->getMessage();
        $template = strtr($violation->getMessageTemplate(), $this->numbersIn($violation->getParameters()));

        // Key-safe only with property names and list positions in the path and no placeholder left in Intakt's constraint message, because a map key or a filled-in value is the model's own text.
        return 1 === preg_match(self::PROPERTY_PATH, $violation->getPropertyPath()) && !str_contains($template, '{{')
            ? VerdictUnreadable::becauseKeySafe($violation->getPropertyPath().': '.$template)
            : VerdictUnreadable::because($reason);
    }

    /**
     * @param array<mixed> $parameters
     *
     * @return array<string>
     */
    private function numbersIn(array $parameters): array
    {
        return array_filter($parameters, static fn (mixed $value): bool => is_string($value) && 1 === preg_match('/^-?\d+$/', $value));
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
