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

    // A result marked as an error (a rate limit, a refused login) is the CLI's own text, never the reviewer's answer.
    private static function claudeCodeResult(string $output): ?string
    {
        $result = json_decode($output, true);

        if (!is_array($result) || 'result' !== ($result[self::TYPE] ?? null) || false !== ($result['is_error'] ?? null)) {
            return null;
        }

        return is_string($result['result'] ?? null) ? $result['result'] : null;
    }

    // Only a turn that completed answers, because a stream cut before turn.completed may end on a message the reviewer had not finished.
    private static function codexMessage(string $output): ?string
    {
        $events = self::codexEvents($output);

        if (null === $events || [] === array_filter($events, static fn (array $event): bool => 'turn.completed' === ($event[self::TYPE] ?? null))) {
            return null;
        }

        $messages = array_filter(array_map(self::agentMessageOf(...), $events), is_string(...));

        return array_last($messages);
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
