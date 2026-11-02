<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ServeReviewTools;

use Closure;
use DomainException;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\Infrastructure\Repository\ListDirectory;
use Intakt\Core\Review\Infrastructure\Repository\ReadFile;
use Intakt\Core\Review\Infrastructure\Repository\SearchFiles;
use Intakt\Core\Review\UseCase\ReadRound\ReadableRounds;
use InvalidArgumentException;
use Mcp\Exception\ToolCallException;
use RuntimeException;

// The one place that decides which head a call may read: the checkout of one round that is still under review, never a path the client names.
final readonly class ReviewedRepository
{
    public function __construct(private ReadableRounds $rounds)
    {
    }

    public function readFile(string $roundId, string $path): string
    {
        return $this->answer(fn (): string => (new ReadFile($this->head($roundId)))($path));
    }

    public function listDirectory(string $roundId, string $path): string
    {
        return $this->answer(fn (): string => (new ListDirectory($this->head($roundId)))($path));
    }

    /**
     * @param Closure(int): void $fileSearched
     */
    public function searchFiles(string $roundId, string $text, string $path, Closure $fileSearched): string
    {
        return $this->answer(fn (): string => (new SearchFiles($this->head($roundId), $fileSearched))($text, $path));
    }

    private function head(string $roundId): Checkout
    {
        $round = $this->rounds->forTool($roundId);

        // The snapshot of a judged round is removed, so it is refused before the filesystem is asked.
        if (RoundState::Judged === $round->state()) {
            throw new ToolCallException('The round is judged, so its head is no longer under review.');
        }

        try {
            return new Checkout($round->checkoutRoot());
        } catch (InvalidArgumentException) {
            throw new ToolCallException('The head of this round is not available.');
        }
    }

    /**
     * @param Closure(): string $read
     */
    private function answer(Closure $read): string
    {
        try {
            return $read();
        } catch (DomainException|RuntimeException|InvalidArgumentException $refused) {
            throw new ToolCallException($refused->getMessage(), previous: $refused);
        }
    }
}
