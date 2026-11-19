<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PDO;
use RuntimeException;

// Reads the instance's own SQLite file (the DATABASE_URL bin/instance gives the journey), so a journey sees the transport and the ledger as the consumer left them.
final readonly class InstanceDatabase
{
    private const string SCHEME = 'sqlite:///';
    private const string QUEUE = 'messenger_messages';
    private const string DEFAULT_QUEUE = 'default';

    public function __construct(private PDO $pdo)
    {
    }

    public static function ofTheInstance(): self
    {
        $url = getenv('DATABASE_URL');

        if (!is_string($url) || !str_starts_with($url, self::SCHEME)) {
            throw new RuntimeException('DATABASE_URL is not a sqlite:/// address: run the journeys with bin/check --journeys.');
        }

        return new self(new PDO('sqlite:'.substr($url, strlen(self::SCHEME)), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
    }

    // The messages of the async transport whose body names the class, delivered or not.
    public function queued(string $messageClass): int
    {
        return $this->count('SELECT COUNT(*) FROM '.self::QUEUE.' WHERE queue_name = ? AND (body || headers) LIKE ?', [self::DEFAULT_QUEUE, '%'.str_replace('\\', '%', $messageClass).'%']);
    }

    // Every message of the async transport, of any class, delivered or not.
    public function waiting(): int
    {
        return $this->count('SELECT COUNT(*) FROM '.self::QUEUE.' WHERE queue_name = ?', [self::DEFAULT_QUEUE]);
    }

    // The messages a consumer fetched and has not acknowledged, which stay on the transport until it does.
    public function fetchedNotAcknowledged(string $messageClass): int
    {
        return $this->count('SELECT COUNT(*) FROM '.self::QUEUE.' WHERE queue_name = ? AND delivered_at IS NOT NULL AND (body || headers) LIKE ?', [self::DEFAULT_QUEUE, '%'.str_replace('\\', '%', $messageClass).'%']);
    }

    public function makeFetchedMessagesAvailable(): void
    {
        $this->pdo->exec('UPDATE '.self::QUEUE.' SET delivered_at = NULL WHERE queue_name = \''.self::DEFAULT_QUEUE.'\'');
    }

    public function ledgerRows(string $kind): int
    {
        return $this->count('SELECT COUNT(*) FROM ledger_event WHERE kind = ?', [$kind]);
    }

    // The newest payloads of the kind, newest first.
    /**
     * @return list<array<string,mixed>>
     */
    public function payloads(string $kind, int $limit): array
    {
        $statement = $this->pdo->prepare('SELECT payload FROM ledger_event WHERE kind = ? ORDER BY id DESC LIMIT '.$limit);
        $statement->execute([$kind]);

        return array_values(array_filter(array_map(static fn (mixed $payload): mixed => is_string($payload) ? json_decode($payload, true) : null, $statement->fetchAll(PDO::FETCH_COLUMN)), is_array(...)));
    }

    /**
     * @param list<string> $parameters
     */
    private function count(string $sql, array $parameters): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }
}
