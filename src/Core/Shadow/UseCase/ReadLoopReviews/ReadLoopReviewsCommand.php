<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\UseCase\ReadLoopReviews;

use Intakt\Core\Shadow\Contract\LoopFinding;
use Intakt\Core\Shadow\Contract\LoopReview;
use Intakt\Core\Shadow\Contract\PullRequestComment;
use Intakt\Core\Shadow\Domain\LoopReviewReader;
use JsonException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:shadow:read-loop-reviews', description: 'Print the loop reviews read from recorded pull request comments')]
final readonly class ReadLoopReviewsCommand
{
    public function __construct(
        private LoopReviewReader $reader,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('JSON file with "identity", "head" and "comments" (author, head, body)')]
        string $file,
    ): int {
        $recorded = $this->load($file);
        if (null === $recorded) {
            $io->error(sprintf('Cannot read recorded comments from %s.', $file));

            return Command::INVALID;
        }

        $reviews = $this->reader->read($recorded['identity'], $recorded['head'], $recorded['comments']);
        foreach ($reviews->reviews as $review) {
            $this->print($io, $review);
        }
        $io->writeln(sprintf('%d loop reviews read', count($reviews->reviews)));

        return Command::SUCCESS;
    }

    private function print(SymfonyStyle $io, LoopReview $review): void
    {
        $io->writeln(sprintf('%s: %s', $review->reviewer, $review->verdict->value), OutputInterface::OUTPUT_RAW);
        foreach ($review->findings as $finding) {
            $io->writeln(sprintf('  %s %s', $finding->severity->value, $this->location($finding)), OutputInterface::OUTPUT_RAW);
        }
    }

    private function location(LoopFinding $finding): string
    {
        return $finding->file.':'.$finding->line;
    }

    /**
     * @return array{identity: string, head: string, comments: list<PullRequestComment>}|null
     */
    private function load(string $file): ?array
    {
        $data = $this->decode($file);
        if (!is_string($data['identity'] ?? null) || !is_string($data['head'] ?? null) || !is_array($data['comments'] ?? null)) {
            return null;
        }

        $comments = array_map($this->comment(...), array_values($data['comments']));

        return in_array(null, $comments, true) ? null : ['identity' => $data['identity'], 'head' => $data['head'], 'comments' => $comments];
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $file): array
    {
        $text = is_file($file) ? file_get_contents($file) : false;
        if (false === $text) {
            return [];
        }

        try {
            $data = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    private function comment(mixed $comment): ?PullRequestComment
    {
        if (!is_array($comment) || !is_string($comment['author'] ?? null) || !is_string($comment['head'] ?? null) || !is_string($comment['body'] ?? null)) {
            return null;
        }

        return new PullRequestComment($comment['author'], $comment['head'], $comment['body']);
    }
}
