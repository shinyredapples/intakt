<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\ReviewServerAnswers;
use Override;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Process\Process;

// Wraps a CLI call that reached the per-review server, because its configuration file must go once the CLI has finished with it (or the review ended without reading it), and the server's answers exist only in the CLI's own stream.
final readonly class ReviewServerCall implements RawResultInterface, ReviewServerAnswers
{
    /**
     * @param list<string> $serverTools
     */
    public function __construct(
        private RawResultInterface $inner,
        private McpConfigFile $configuration,
        private array $serverTools,
    ) {
    }

    #[Override]
    public function forgetTheCredential(): void
    {
        $this->configuration->remove();
    }

    #[Override]
    public function getData(): array
    {
        try {
            return $this->inner->getData();
        } finally {
            $this->configuration->remove();
        }
    }

    #[Override]
    public function getDataStream(): iterable
    {
        try {
            yield from $this->inner->getDataStream();
        } finally {
            $this->configuration->remove();
        }
    }

    #[Override]
    public function getObject(): object
    {
        return $this->inner->getObject();
    }

    #[Override]
    public function reviewServerAnswers(): array
    {
        $process = $this->inner->getObject();
        $blocks = $process instanceof Process ? $this->contentBlocks($process->getOutput()) : [];
        $calls = array_filter(array_column(array_filter($blocks, fn (array $block): bool => 'tool_use' === ($block['type'] ?? null) && in_array($block['name'] ?? null, $this->serverTools, true)), 'id'), is_string(...));
        $answers = array_column(array_filter($blocks, static fn (array $block): bool => 'tool_result' === ($block['type'] ?? null)), 'content', 'tool_use_id');

        return array_values(array_map(fn (string $id): string => $this->textOf($answers[$id] ?? null), $calls));
    }

    /**
     * @return list<array<mixed>>
     */
    private function contentBlocks(string $stream): array
    {
        $blocks = [];

        foreach (explode(PHP_EOL, $stream) as $line) {
            $event = json_decode($line, true);
            $content = is_array($event) && is_array($event['message'] ?? null) ? $event['message']['content'] ?? null : null;
            array_push($blocks, ...array_values(array_filter(is_array($content) ? $content : [], is_array(...))));
        }

        return $blocks;
    }

    // The CLI reports an answer as one string or as a list of text blocks; anything else, or a list with any other block, gives no text, so it cannot pass as a whole read.
    private function textOf(mixed $content): string
    {
        if (is_string($content) || !is_array($content)) {
            return is_string($content) ? $content : '';
        }

        $texts = array_map(static fn (mixed $part): ?string => is_array($part) && 'text' === ($part['type'] ?? null) && is_string($part['text'] ?? null) ? $part['text'] : null, $content);

        return in_array(null, $texts, true) ? '' : implode('', $texts);
    }
}
