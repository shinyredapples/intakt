<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use DateTimeImmutable;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;

final class Issues
{
    /**
     * @param list<string> $labels
     */
    public static function task(int $number, array $labels = ['intakt'], string $author = 'ana', string $body = '', string $updatedAt = '2026-11-10T10:00:00Z', ?string $due = null): ListedIssue
    {
        return new ListedIssue($number, 'Task '.$number, $body, $labels, $author, new DateTimeImmutable($updatedAt), null === $due ? null : new DateTimeImmutable($due), false);
    }

    public static function pullRequest(int $number, string $body): ListedIssue
    {
        return new ListedIssue($number, 'Pull '.$number, $body, [], 'ana', new DateTimeImmutable('2026-11-10T10:00:00Z'), null, true);
    }
}
