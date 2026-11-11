<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Infrastructure;

use Intakt\Core\Shadow\Contract\PullRequestComment;
use Intakt\Core\Shadow\Domain\PostedComment;
use Intakt\Core\Shadow\Domain\RecordedPullRequest;
use JsonException;
use TypeError;

// Read here, not in the domain, because the file format belongs to the weekly report's door alone until a forge reads the comments.
final readonly class RecordedPullRequestFile
{
    public static function read(string $file): ?RecordedPullRequest
    {
        $data = self::decode($file);
        if (!is_int($data['pullRequest'] ?? null) || !is_string($data['loop'] ?? null) || !is_string($data['intakt'] ?? null) || !is_array($data['comments'] ?? null)) {
            return null;
        }

        try {
            $comments = array_map(self::comment(...), array_values($data['comments']));
        } catch (TypeError) {
            return null;
        }

        return new RecordedPullRequest($data['pullRequest'], $data['loop'], $data['intakt'], $comments);
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $file): array
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

    private static function comment(mixed $raw): PostedComment
    {
        $comment = is_array($raw) ? $raw : throw new TypeError('not a comment');
        $postedAt = RecordedMoment::parse(self::text($comment, 'postedAt')) ?? throw new TypeError('not a moment');

        return new PostedComment(new PullRequestComment(self::text($comment, 'author'), self::text($comment, 'head'), self::text($comment, 'body')), $postedAt);
    }

    /**
     * @param array<mixed> $comment
     */
    private static function text(array $comment, string $key): string
    {
        $value = $comment[$key] ?? null;

        return is_string($value) ? $value : throw new TypeError(sprintf('no text for %s', $key));
    }
}
