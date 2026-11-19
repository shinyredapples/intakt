<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Symfony\AI\Platform\Bridge\ClaudeCode\TokenUsageExtractor as ClaudeCodeUsage;
use Symfony\AI\Platform\Bridge\Codex\TokenUsageExtractor as CodexUsage;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

// Untrusted, because the reviewer wrote it: only its shape is checked here, and the verdict in it is checked by whoever reads it.
final readonly class ReviewJobAnswer
{
    private const string TYPE = 'type';

    // The only tool of the job's Claude Code that reads one file, because Grep and Glob can answer without opening a file of the checkout.
    private const string READ_TOOL = 'Read';

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

    /**
     * @throws ReviewJobFailure
     */
    public static function read(Provider $provider, string $output): self
    {
        $events = self::events($output) ?? [];
        $text = match ($provider) {
            Provider::ClaudeCode => self::claudeCodeResult($events),
            Provider::Codex => self::codexMessage($events),
            default => null,
        };

        if (null === $text || '' === $text) {
            throw ReviewJobFailure::unreadableAnswer($provider);
        }

        return match ($provider) {
            Provider::ClaudeCode => new self($text, self::claudeCodeReadACheckoutFile($events), self::claudeCodeUsage($events)),
            // No read counts, because the job's Codex has its shell and every other tool off, so its events can show no file it read.
            default => new self($text, false, self::codexUsage($events)),
        };
    }

    // The last result event, because the stream ends on it; refused when marked as an error, because such a result (a rate limit, a refused login) is the CLI's own text, never the reviewer's answer.
    /**
     * @param list<array<mixed>> $events
     */
    private static function claudeCodeResult(array $events): ?string
    {
        $result = self::lastResultOf($events);

        if (null === $result || false !== ($result['is_error'] ?? null)) {
            return null;
        }

        return is_string($result['result'] ?? null) ? $result['result'] : null;
    }

    /**
     * @param list<array<mixed>> $events
     *
     * @return array<mixed>|null
     */
    private static function lastResultOf(array $events): ?array
    {
        $results = array_filter($events, static fn (array $event): bool => 'result' === ($event[self::TYPE] ?? null));

        return array_last($results);
    }

    // A Read call of a file under the checkout that the CLI answered without a tool error, because a call that failed or read elsewhere showed the reviewer nothing of the change.
    /**
     * @param list<array<mixed>> $events
     */
    private static function claudeCodeReadACheckoutFile(array $events): bool
    {
        $reads = [];
        foreach (self::contentOf($events, 'assistant') as $block) {
            $id = $block['id'] ?? null;
            if ('tool_use' === ($block[self::TYPE] ?? null) && self::READ_TOOL === ($block['name'] ?? null) && is_string($id) && self::readsInTheCheckout($block['input'] ?? null)) {
                $reads[$id] = true;
            }
        }

        foreach (self::contentOf($events, 'user') as $block) {
            $id = $block['tool_use_id'] ?? null;
            if ('tool_result' === ($block[self::TYPE] ?? null) && is_string($id) && isset($reads[$id]) && true !== ($block['is_error'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    private static function readsInTheCheckout(mixed $input): bool
    {
        $path = is_array($input) ? $input['file_path'] ?? null : null;

        return is_string($path) && self::inTheCheckout($path);
    }

    // Under the mount point and without a step up, because the CLI resolves a relative or dotted path itself and could leave the checkout.
    private static function inTheCheckout(string $path): bool
    {
        return str_starts_with($path, ReviewJob::CHECKOUT.'/') && !in_array('..', explode('/', $path), true);
    }

    /**
     * @param list<array<mixed>> $events
     *
     * @return list<array<mixed>>
     */
    private static function contentOf(array $events, string $type): array
    {
        $blocks = [];
        foreach ($events as $event) {
            $message = $type === ($event[self::TYPE] ?? null) ? $event['message'] ?? null : null;
            $content = is_array($message) ? $message['content'] ?? null : null;
            foreach (is_array($content) ? $content : [] as $block) {
                if (is_array($block)) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }

    // The usage of the final result, read by the same extractor as for the CLI in the app's own process, because that result covers every turn of the run.
    /**
     * @param list<array<mixed>> $events
     *
     * @return list<TokenUsageInterface>
     */
    private static function claudeCodeUsage(array $events): array
    {
        return self::usageIn(new ClaudeCodeUsage(), array_filter([self::lastResultOf($events)]));
    }

    // Each completed turn reports its own usage, so every one is kept.
    /**
     * @param list<array<mixed>> $events
     *
     * @return list<TokenUsageInterface>
     */
    private static function codexUsage(array $events): array
    {
        return self::usageIn(new CodexUsage(), array_filter($events, static fn (array $event): bool => 'turn.completed' === ($event[self::TYPE] ?? null)));
    }

    // Only a usage object with figures counts, because an empty one would settle the job as having spent nothing.
    /**
     * @param array<array<mixed>> $events
     *
     * @return list<TokenUsageInterface>
     */
    private static function usageIn(ClaudeCodeUsage|CodexUsage $extractor, array $events): array
    {
        $usage = [];
        foreach ($events as $event) {
            $read = is_array($event['usage'] ?? null) ? $extractor->extract(new InMemoryRawResult($event)) : null;
            if ($read instanceof TokenUsageInterface && (null !== $read->getPromptTokens() || null !== $read->getCompletionTokens())) {
                $usage[] = $read;
            }
        }

        return $usage;
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
