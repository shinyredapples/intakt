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
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Redacts the written line rather than the record, because a processor cannot reach an exception's message and trace, which the formatter writes.
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
        $held = array_values(array_filter([...$credentials, ...$accountCredentials], static fn (?string $credential): bool => null !== $credential && '' !== $credential));
        // Also as JSON writes them, because a credential with a quote, a backslash or a line break is escaped inside the line.
        $escaped = array_map(static fn (string $credential): string => substr((string) json_encode($credential, Utils::DEFAULT_JSON_FLAGS), 1, -1), $held);
        $this->redaction = new Redaction(...$held, ...$escaped);
    }

    #[Override]
    public function format(LogRecord $record): string
    {
        return $this->redaction->of($this->json->format($record));
    }

    /**
     * @param array<LogRecord> $records
     */
    #[Override]
    public function formatBatch(array $records): string
    {
        return $this->redaction->of($this->json->formatBatch($records));
    }
}
