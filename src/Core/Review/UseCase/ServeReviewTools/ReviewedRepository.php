<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ServeReviewTools;

use Closure;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\CheckoutNotADirectory;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\PathNotInCheckout;
use Intakt\Core\Review\Contract\PathOutsideCheckout;
use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Core\Review\Contract\RoundUpdates;
use Intakt\Core\Review\Contract\UntrustedSource;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\Infrastructure\Repository\ListDirectory;
use Intakt\Core\Review\Infrastructure\Repository\PathNotReadable;
use Intakt\Core\Review\Infrastructure\Repository\ReadFile;
use Intakt\Core\Review\Infrastructure\Repository\SearchFiles;
use Intakt\Core\Review\Infrastructure\Repository\SearchTextEmpty;
use Intakt\Core\Review\Infrastructure\RoundToolCalls;
use Intakt\Core\Review\UseCase\ReadRound\ReadableRounds;
use Mcp\Exception\ToolCallException;
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
        private RoundUpdates $updates,
        private PromptAssembly $assembly,
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
        $this->recordedRead($round, $head->relative($real));

        return $answer;
    }

    public function listDirectory(string $path): string
    {
        $round = $this->round();
        $head = $this->head($round);
        $answer = $this->answer($round, ListDirectoryTool::NAME, static fn (): string => (new ListDirectory($head))($path));
        $this->recordedCall($round, ListDirectoryTool::NAME);

        return $answer;
    }

    public function searchFiles(string $text, string $path): string
    {
        $round = $this->round();
        $head = $this->head($round);
        $answer = $this->answer($round, SearchFilesTool::NAME, fn (): string => (new SearchFiles($head, $this->fileSearched))($text, $path));
        $this->recordedCall($round, SearchFilesTool::NAME);

        return $answer;
    }

    private function recordedCall(ReviewRound $round, string $tool): void
    {
        $this->calls->called($round, $tool);
        $this->updates->summaryChanged($round->id());
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
        } catch (CheckoutNotADirectory $unavailable) {
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

    // A refusal is still recorded as a call, because the reviewer made it.
    /**
     * @param Closure(): string $step
     */
    private function refusedAsCall(ReviewRound $round, string $tool, Closure $step): string
    {
        try {
            return $step();
        } catch (PathNotInCheckout|PathOutsideCheckout|PathNotReadable|SearchTextEmpty $refused) {
            $this->recordedCall($round, $tool);

            throw new ToolCallException($this->assembly->toolAnswer($this->fence->wrap(UntrustedSource::ToolResult, $refused->getMessage())), $refused->getCode(), previous: $refused);
        }
    }

    // Fenced here and not only by the reviewer's toolbox, because a subscription CLI calls this server itself and puts the answer into its prompt as it comes; a refusal too, since it echoes the path the reviewed code chose.
    /**
     * @param Closure(): string $read
     */
    private function answer(ReviewRound $round, string $tool, Closure $read): string
    {
        return $this->refusedAsCall($round, $tool, fn (): string => $this->assembly->toolAnswer($this->fence->wrap(UntrustedSource::ToolResult, $read())));
    }

    // Announced after the record, because the summary is computed from what is stored and would lag one call behind if it were announced before.
    private function recordedRead(ReviewRound $round, string $path): void
    {
        $this->calls->read($round, ReadFileTool::NAME, $path);
        $this->updates->summaryChanged($round->id());
    }
}
