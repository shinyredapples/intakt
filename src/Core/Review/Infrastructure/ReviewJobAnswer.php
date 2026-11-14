<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;

// Untrusted, because the reviewer wrote it: only its shape is checked here, and the verdict in it is checked by whoever reads it.
final readonly class ReviewJobAnswer
{
    private const string TYPE = 'type';

    private function __construct(
        public string $text,
    ) {
    }

    /**
     * @throws ReviewJobFailure
     */
    public static function read(Provider $provider, string $output): self
    {
        $text = match ($provider) {
            Provider::ClaudeCode => self::claudeCodeResult($output),
            Provider::Codex => self::codexMessage($output),
            default => null,
        };

        return null === $text || '' === $text ? throw ReviewJobFailure::unreadableAnswer($provider) : new self($text);
    }

    // Refused when marked as an error, because such a result (a rate limit, a refused login) is the CLI's own text, never the reviewer's answer.
    private static function claudeCodeResult(string $output): ?string
    {
        $result = json_decode($output, true);

        if (!is_array($result) || 'result' !== ($result[self::TYPE] ?? null) || false !== ($result['is_error'] ?? null)) {
            return null;
        }

        return is_string($result['result'] ?? null) ? $result['result'] : null;
    }

    // The last message answers only once its own turn completed, because a turn that failed or was cut off may end on a message the reviewer had not finished, and a later turn's turn.completed says nothing about it.
    private static function codexMessage(string $output): ?string
    {
        $answer = null;
        $pending = null;

        foreach (self::codexEvents($output) ?? [] as $event) {
            $message = self::agentMessageOf($event);
            if (null !== $message) {
                [$answer, $pending] = [null, $message];
                continue;
            }

            match ($event[self::TYPE] ?? null) {
                'turn.completed' => [$answer, $pending] = [$pending ?? $answer, null],
                'turn.started', 'turn.failed' => $pending = null,
                default => null,
            };
        }

        return $answer;
    }

    /**
     * @return list<array<mixed>>|null
     */
    // Every line must be an event, so output that is not the CLI's event stream is never searched for a message.
    private static function codexEvents(string $output): ?array
    {
        $events = [];

        foreach (array_filter(explode("\n", $output), static fn (string $line): bool => '' !== trim($line)) as $line) {
            $event = json_decode($line, true);
            if (!is_array($event) || array_is_list($event)) {
                return null;
            }
            $events[] = $event;
        }

        return $events;
    }

    /**
     * @param array<mixed> $event
     */
    private static function agentMessageOf(array $event): ?string
    {
        $item = 'item.completed' === ($event[self::TYPE] ?? null) ? $event['item'] ?? null : null;

        if (!is_array($item) || 'agent_message' !== ($item[self::TYPE] ?? null)) {
            return null;
        }

        return is_string($item['text'] ?? null) ? $item['text'] : null;
    }
}
