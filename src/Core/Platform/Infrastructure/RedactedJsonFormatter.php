<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Infrastructure;

use Intakt\Core\Platform\Contract\Redaction;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;
use Monolog\Utils;
use Override;
use SensitiveParameter;
use stdClass;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Redacts what the formatter writes rather than the record, because a processor cannot reach an exception's message and trace.
final readonly class RedactedJsonFormatter implements FormatterInterface
{
    private JsonFormatter $json;
    private Redaction $redaction;

    /**
     * @param list<?string> $credentials
     * @param list<string>  $accountCredentials
     */
    public function __construct(
        #[Autowire(param: 'intakt.credentials')]
        #[SensitiveParameter]
        array $credentials,
        #[Autowire(param: 'intakt.ai.credentials')]
        #[SensitiveParameter]
        array $accountCredentials,
    ) {
        // One line per record with the trace, so a failure is one journal entry.
        $this->json = new JsonFormatter(includeStacktraces: true);
        $this->redaction = new Redaction(...array_filter([...$credentials, ...$accountCredentials], static fn (?string $credential): bool => null !== $credential && '' !== $credential));
    }

    #[Override]
    public function format(LogRecord $record): string
    {
        return $this->redacted($this->json->format($record))."\n";
    }

    /**
     * @param array<LogRecord> $records
     */
    #[Override]
    public function formatBatch(array $records): string
    {
        return $this->redacted($this->json->formatBatch($records));
    }

    // Each decoded string, because in the written line a JSON escape (a \n before a header, a \" around a value) hides where a secret starts and ends.
    private function redacted(string $json): string
    {
        return json_decode($json, flags: JSON_THROW_ON_ERROR)
            |> $this->redactedValue(...)
            |> (static fn (mixed $value): string => Utils::jsonEncode($value, Utils::DEFAULT_JSON_FLAGS, true));
    }

    private function redactedValue(mixed $value): mixed
    {
        return match (true) {
            is_string($value) => $this->redaction->of($value),
            is_array($value) => $this->redactedFields($value),
            $value instanceof stdClass => (object) $this->redactedFields(get_object_vars($value)),
            default => $value,
        };
    }

    /**
     * @param array<array-key, mixed> $fields
     *
     * @return array<array-key, mixed>
     */
    private function redactedFields(array $fields): array
    {
        $redacted = [];
        foreach ($fields as $name => $value) {
            $redacted[$name] = is_string($name) && $this->redaction->namesACredential($name) ? Redaction::REDACTED : $this->redactedValue($value);
        }

        return $redacted;
    }
}
