<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Infrastructure;

use DateTimeInterface;
use Intakt\Core\Platform\Contract\Redaction;
use Stringable;
use Symfony\Component\Clock\ClockInterface;
use Throwable;

// The log line formatter of the service's logger: one JSON line per record, each value redacted before encoding, so a redaction never cuts into the JSON or another field.
final readonly class RedactedJsonFormatter
{
    public function __construct(
        private Redaction $redaction,
        private ClockInterface $clock,
        private HeldCredentials $heldCredentials,
    ) {
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function __invoke(string $level, string|Stringable $message, array $context): string
    {
        $record = [
            'time' => $this->clock->now()->format(DateTimeInterface::RFC3339_EXTENDED),
            'level' => $level,
            'message' => $this->interpolated((string) $message, $context),
            'context' => array_map($this->loggable(...), $context),
        ];

        return $this->encoded($this->redacted($record, $this->heldCredentials->values()));
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
     * @param list<string> $credentials
     */
    private function redacted(mixed $value, array $credentials): mixed
    {
        if (is_string($value)) {
            return $this->redaction->redact($value, ...$credentials);
        }

        if (!is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            $name = is_string($key) ? $this->redaction->redact($key, ...$credentials) : $key;
            $redacted[$name] = $this->namesHeader($key) ? Redaction::MARKER : $this->redacted($item, $credentials);
        }

        return $redacted;
    }

    // A map holds a header's value apart from its name, so the redaction is asked whether it would redact a value written after this name.
    private function namesHeader(int|string $key): bool
    {
        $sample = $key.': value';

        return is_string($key) && $this->redaction->redact($sample) !== $sample;
    }

    private function encoded(mixed $record): string
    {
        $line = json_encode($record, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);

        return false === $line ? '{"level":"error","message":"A log record could not be encoded."}' : $line;
    }
}
