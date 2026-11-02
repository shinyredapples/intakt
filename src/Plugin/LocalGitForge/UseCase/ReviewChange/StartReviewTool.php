<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Mcp\Contract\ToolAccess;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\ClientGateway;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;

// It waits for the round and reports progress, because the snapshot the reviewers read is removed as soon as this call returns.
#[McpTool(
    name: 'start_review',
    description: 'Start a review round of a branch of a local repository with the project\'s panel, wait for it and answer with the decision and the act list. Send a progressToken to get progress notifications.',
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true),
)]
#[IsGrantedTool(ToolAccess::Write->value)]
final readonly class StartReviewTool
{
    public function __construct(private LocalChangeReview $review)
    {
    }

    /**
     * @param array<mixed> $review
     */
    public function __invoke(
        ClientGateway $client,
        #[Schema(
            type: 'object',
            description: 'The review to start: project, task (what the branch builds) and change (the branch) are required; repository (default "."), base (default "main") and round (the number the new round must have) are optional.',
            properties: [
                'project' => ['type' => 'string', 'minLength' => 1],
                'task' => ['type' => 'string', 'minLength' => 1],
                'change' => ['type' => 'string', 'minLength' => 1],
                'repository' => ['type' => 'string'],
                'base' => ['type' => 'string'],
                'round' => ['type' => 'integer', 'minimum' => 1],
            ],
            required: ['project', 'task', 'change'],
            additionalProperties: false,
        )]
        array $review,
    ): StartedReview {
        $input = $this->inputOf($review);

        try {
            return StartedReview::of($this->review->of($input, new ForwardedProgress($client)));
        } catch (ReportableFailure $failure) {
            throw new ToolCallException($failure->getMessage(), $failure->getCode(), previous: $failure);
        }
    }

    // Checked here as well as in the schema, because a client may skip the schema and the input is untrusted.
    /**
     * @param array<mixed> $review
     */
    private function inputOf(array $review): ReviewChangeInput
    {
        $input = new ReviewChangeInput();
        $input->project = $this->requiredText($review, 'project');
        $input->task = $this->requiredText($review, 'task');
        $input->change = $this->requiredText($review, 'change');
        $input->repository = $this->optionalText($review, 'repository', $input->repository);
        $input->base = $this->optionalText($review, 'base', $input->base);
        $input->round = $this->round($review);

        return $input;
    }

    /**
     * @param array<mixed> $review
     */
    private function requiredText(array $review, string $name): string
    {
        $value = $review[$name] ?? null;

        return is_string($value) && '' !== $value ? $value : throw new ToolCallException(sprintf('%s is required and must be a non-empty string.', $name));
    }

    /**
     * @param array<mixed> $review
     */
    private function optionalText(array $review, string $name, string $default): string
    {
        $value = $review[$name] ?? $default;

        return is_string($value) && '' !== $value ? $value : throw new ToolCallException(sprintf('%s must be a non-empty string.', $name));
    }

    /**
     * @param array<mixed> $review
     */
    private function round(array $review): ?int
    {
        $round = $review['round'] ?? null;

        if (null === $round) {
            return null;
        }

        return is_int($round) && $round >= 1 ? $round : throw new ToolCallException('round must be an integer of 1 or more.');
    }
}
