<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Closure;
use Intakt\Core\Ai\Contract\Provider;
use Symfony\AI\Platform\Bridge\Codex\TokenUsageExtractor;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Untrusted, because the reviewer wrote it: only its shape is checked here, and the verdict in it is checked by whoever reads it.
#[Exclude]
final readonly class ReviewJobAnswer
{
    private const string TYPE = 'type';

    /**
     * @param list<string>              $readFiles
     * @param list<TokenUsageInterface> $usage
     */
    private function __construct(
        public string $text,
        // The checkout's paths the CLI's own events show as read, never the model's text; whether the head holds them as regular files is checked against the head, because a read follows a committed link.
        public array $readFiles,
        // What the CLI reported it spent; empty when it reported nothing, so the vendor leaves the call unsettled instead of settling it as zero.
        public array $usage,
    ) {
    }

    /**
     * @param (Closure(string): string)|null $redacted
     *
     * @throws ReviewJobFailure
     */
    public static function read(Provider $provider, string $output, ?Closure $redacted = null): self
    {
        $events = self::redacted(self::events($output) ?? [], $redacted);
        $claudeCode = new ClaudeCodeJobEvents($events);
        $text = match ($provider) {
            Provider::ClaudeCode => $claudeCode->answer(),
            Provider::Codex => self::codexMessage($events),
            default => null,
        };

        if (null === $text || '' === $text) {
            throw ReviewJobFailure::unreadableAnswer($provider);
        }

        return Provider::ClaudeCode === $provider
            ? new self($text, $claudeCode->readFiles(), $claudeCode->usage())
            // No read counts, because the job's Codex has its shell and every other tool off, so its events can show no file it read.
            : new self($text, [], self::codexUsage($events));
    }

    // After decoding, because JSON may escape any character of a value, and before anything else reads the events.
    /**
     * @param list<array<mixed>>             $events
     * @param (Closure(string): string)|null $redacted
     *
     * @return list<array<mixed>>
     */
    private static function redacted(array $events, ?Closure $redacted): array
    {
        if ($redacted instanceof Closure) {
            array_walk_recursive($events, static function (mixed &$value) use ($redacted): void {
                $value = is_string($value) ? $redacted($value) : $value;
            });
        }

        return $events;
    }

    /**
     * @return list<array<mixed>>|null
     */
    // Every line must be an event, so output that is not the CLI's event stream is never searched for an answer, a read or usage.
    private static function events(string $output): ?array
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

    // The last message answers only once its own turn completed, because a turn that failed or was cut off may end on a message the reviewer had not finished, and a later turn's turn.completed says nothing about it.
    /**
     * @param list<array<mixed>> $events
     */
    private static function codexMessage(array $events): ?string
    {
        $answer = null;
        $pending = null;

        foreach ($events as $event) {
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

    // Each completed turn reports its own usage, so every one is kept.
    /**
     * @param list<array<mixed>> $events
     *
     * @return list<TokenUsageInterface>
     */
    private static function codexUsage(array $events): array
    {
        return ReportedUsage::in(new TokenUsageExtractor(), array_filter($events, static fn (array $event): bool => 'turn.completed' === ($event[self::TYPE] ?? null)));
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
