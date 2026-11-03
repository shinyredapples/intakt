<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Infrastructure;

use DateTimeInterface;
use Intakt\Core\Platform\Contract\Redaction;
use SensitiveParameter;
use Stringable;
use Symfony\Component\Clock\ClockInterface;
use Throwable;

// The log line formatter of the service's logger: one JSON line per record, redacted last so every escaping of a secret is covered.
final readonly class RedactedJsonFormatter
{
    /** @var list<string> */
    private array $heldCredentials;

    /**
     * @param array<array-key, string|null> $heldCredentials values of the credentials this install holds; unset ones are null
     */
    public function __construct(
        private Redaction $redaction,
        private ClockInterface $clock,
        #[SensitiveParameter]
        array $heldCredentials,
    ) {
        $this->heldCredentials = array_values(array_filter($heldCredentials, static fn (?string $value): bool => null !== $value && '' !== $value));
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function __invoke(string $level, string|Stringable $message, array $context, bool $prefixDate = true): string
    {
        $record = [
            'time' => $this->clock->now()->format(DateTimeInterface::RFC3339_EXTENDED),
            'level' => $level,
            'message' => $this->interpolated((string) $message, $context),
            'context' => array_map($this->loggable(...), $context),
        ];

        return $this->redaction->redact($this->encoded($record), ...$this->heldCredentials);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private function interpolated(string $message, array $context): string
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable) {
                $replacements['{'.$key.'}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }

    private function loggable(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Throwable => $this->failure($value),
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::RFC3339_EXTENDED),
            is_object($value) => '[object '.$value::class.']',
            is_array($value) => array_map($this->loggable(...), $value),
            is_resource($value) => '[resource]',
            default => $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(Throwable $failure): array
    {
        $previous = $failure->getPrevious();

        return [
            'class' => $failure::class,
            'message' => $failure->getMessage(),
            'at' => $failure->getFile().':'.$failure->getLine(),
            'trace' => $failure->getTraceAsString(),
            'previous' => $previous instanceof Throwable ? $this->failure($previous) : null,
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function encoded(array $record): string
    {
        return json_encode($record, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES) ?: '{"level":"error","message":"A log record could not be encoded."}';
    }
}
