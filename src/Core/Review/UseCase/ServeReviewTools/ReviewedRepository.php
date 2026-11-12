<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ServeReviewTools;

use Closure;
use DomainException;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\UntrustedSource;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\Infrastructure\Repository\ListDirectory;
use Intakt\Core\Review\Infrastructure\Repository\ReadFile;
use Intakt\Core\Review\Infrastructure\Repository\SearchFiles;
use Intakt\Core\Review\Infrastructure\RoundToolCalls;
use Intakt\Core\Review\UseCase\ReadRound\ReadableRounds;
use InvalidArgumentException;
use Mcp\Exception\ToolCallException;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

// Because a client must not name the head, this is the one place that decides it: the checkout of the round its credential was issued for, while that round is under review.
final readonly class ReviewedRepository
{
    /**
     * @param (Closure(int): void)|null $fileSearched
     */
    public function __construct(
        private ReadableRounds $rounds,
        private Security $security,
        private Fence $fence,
        private RoundToolCalls $calls,
        private ?Closure $fileSearched = null,
    ) {
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
        $round = $this->round();
        $head = $this->head($round);
        $real = $this->refusedAsCall($round, ReadFileTool::NAME, static fn (): string => $head->resolve($path));
        $answer = $this->answer($round, ReadFileTool::NAME, static fn (): string => new ReadFile($head)->contentAt($real, $path));
        // Recorded as the checkout resolved it, so two spellings of one file count as one file read.
        $this->calls->read($round, ReadFileTool::NAME, $head->relative($real));

        return $answer;
    }

    public function listDirectory(string $path): string
    {
        $round = $this->round();
        $head = $this->head($round);
        $answer = $this->answer($round, ListDirectoryTool::NAME, static fn (): string => (new ListDirectory($head))($path));
        $this->calls->called($round, ListDirectoryTool::NAME);

        return $answer;
    }

    public function searchFiles(string $text, string $path): string
    {
        $round = $this->round();
        $head = $this->head($round);
        $answer = $this->answer($round, SearchFilesTool::NAME, fn (): string => (new SearchFiles($head, $this->fileSearched))($text, $path));
        $this->calls->called($round, SearchFilesTool::NAME);

        return $answer;
    }

    private function round(): ReviewRound
    {
        return $this->rounds->forTool($this->credentialRound());
    }

    private function head(ReviewRound $round): Checkout
    {
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

        if (!$reviewer instanceof UserInterface || !$this->security->isGranted(ReviewCredential::REVIEWER_ROLE)) {
            throw new ToolCallException('The request carries no review credential.');
        }

        return $reviewer->getUserIdentifier();
    }

    // Fenced here and not only by the reviewer's toolbox, because a subscription CLI calls this server itself and puts the answer into its prompt as it comes; a refusal too, since it echoes the path the reviewed code chose. A refusal is still recorded as a call, because the reviewer made it.
    /**
     * @param Closure(): string $read
     */
    private function answer(ReviewRound $round, string $tool, Closure $read): string
    {
        return $this->refusedAsCall($round, $tool, fn (): string => $this->fence->wrap(UntrustedSource::ToolResult, $read()));
    }

    /**
     * @template T
     *
     * @param Closure(): T $step
     *
     * @return T
     */
    private function refusedAsCall(ReviewRound $round, string $tool, Closure $step): mixed
    {
        try {
            return $step();
        } catch (DomainException|RuntimeException|InvalidArgumentException $refused) {
            $this->calls->called($round, $tool);

            throw new ToolCallException($this->fence->wrap(UntrustedSource::ToolResult, $refused->getMessage()), $refused->getCode(), previous: $refused);
        }
    }
}
