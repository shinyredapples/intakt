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
use Symfony\Bundle\SecurityBundle\Security;

// Because a client must not name the head, this is the one place that decides it: the checkout of the round its credential was issued for, while that round is under review.
final readonly class ReviewedRepository
{
    /**
     * @param (Closure(int): void)|null $fileSearched
     */
    public function __construct(private ReadableRounds $rounds, private Security $security, private ?Closure $fileSearched = null)
    {
    }

    /**
     * @param Closure(int): void $fileSearched
     */
    public function reporting(Closure $fileSearched): self
    {
        return clone ($this, ['fileSearched' => $fileSearched]);
    }

    public function readFile(string $path): string
    {
        return $this->answer(fn (): string => (new ReadFile($this->head()))($path));
    }

    public function listDirectory(string $path): string
    {
        return $this->answer(fn (): string => (new ListDirectory($this->head()))($path));
    }

    public function searchFiles(string $text, string $path): string
    {
        return $this->answer(fn (): string => (new SearchFiles($this->head(), $this->fileSearched))($text, $path));
    }

    private function head(): Checkout
    {
        $round = $this->rounds->forTool($this->credentialRound());

        // The snapshot of a judged round is removed, so it is refused before the filesystem is asked.
        if (RoundState::Judged === $round->state()) {
            throw new ToolCallException('The round is judged, so its head is no longer under review.');
        }

        try {
            return new Checkout($round->checkoutRoot());
        } catch (InvalidArgumentException $unavailable) {
            throw new ToolCallException('The head of this round is not available.', $unavailable->getCode(), previous: $unavailable);
        }
    }

    private function credentialRound(): string
    {
        $reviewer = $this->security->getUser();

        if (null === $reviewer || !$this->security->isGranted(ReviewCredential::REVIEWER_ROLE)) {
            throw new ToolCallException('The request carries no review credential.');
        }

        return $reviewer->getUserIdentifier();
    }

    /**
     * @param Closure(): string $read
     */
    private function answer(Closure $read): string
    {
        try {
            return $read();
        } catch (DomainException|RuntimeException|InvalidArgumentException $refused) {
            throw new ToolCallException($refused->getMessage(), $refused->getCode(), previous: $refused);
        }
    }
}
