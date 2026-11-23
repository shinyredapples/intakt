<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

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
     * @param list<TokenUsageInterface> $usage
     */
    private function __construct(
        public string $text,
        // Read from the CLI's own events, never from the model's text, so the vendor counts an approve only when the job read a file of the checkout.
        public bool $readTheCheckout,
        // What the CLI reported it spent; empty when it reported nothing, so the vendor leaves the call unsettled instead of settling it as zero.
        public array $usage,
    ) {
    }

    // The checkout is the host folder the job mounted, resolved to its real path.
    /**
     * @throws ReviewJobFailure
     */
    public static function read(Provider $provider, string $output, string $checkout): self
    {
        $events = self::events($output) ?? [];
        $claudeCode = new ClaudeCodeJobEvents($events, $checkout);
        $text = match ($provider) {
            Provider::ClaudeCode => $claudeCode->answer(),
            Provider::Codex => self::codexMessage($events),
            default => null,
        };

        if (null === $text || '' === $text) {
            throw ReviewJobFailure::unreadableAnswer($provider);
        }

        return Provider::ClaudeCode === $provider
            ? new self($text, $claudeCode->readACheckoutFile(), $claudeCode->usage())
            // No read counts, because the job's Codex has its shell and every other tool off, so its events can show no file it read.
            : new self($text, false, self::codexUsage($events));
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
     *
     * @throws ReviewJobFailure
     */
    private static function codexUsage(array $events): array
    {
        return ReportedUsage::in(Provider::Codex, new TokenUsageExtractor(), array_filter($events, static fn (array $event): bool => 'turn.completed' === ($event[self::TYPE] ?? null)));
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
