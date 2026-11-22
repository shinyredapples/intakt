<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Psr\Log\AbstractLogger;
use Stringable;

// Keeps the ledger reads DBAL's logging middleware reports, so a test can run each read again and count the rows it returns.
final class LedgerSelects extends AbstractLogger
{
    /**
     * @var list<array{string, list<mixed>}>
     */
    private array $queries = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $sql = $context['sql'] ?? null;

        if (is_string($sql) && str_starts_with($sql, 'SELECT') && str_contains($sql, 'ledger_event')) {
            $params = $context['params'] ?? [];
            $this->queries[] = [$sql, is_array($params) ? array_values($params) : []];
        }
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    public function queries(): array
    {
        return $this->queries;
    }

    public function clear(): void
    {
        $this->queries = [];
    }
}
