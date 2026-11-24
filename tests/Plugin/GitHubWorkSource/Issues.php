<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use LogicException;

final class Issues
{
    private const string MOMENT = 'Y-m-d\TH:i:sp';

    /**
     * @param list<string>                                                            $labels
     * @param array{author?: string, body?: string, updatedAt?: string, due?: string} $with
     */
    public static function task(int $number, array $labels = ['intakt'], array $with = []): ListedIssue
    {
        $due = $with['due'] ?? null;

        return new ListedIssue($number, 'Task '.$number, $with['body'] ?? '', new IssueLabels(...$labels), $with['author'] ?? 'ana', self::moment($with['updatedAt'] ?? '2026-11-10T10:00:00Z'), null === $due ? null : self::moment($due), false);
    }

    public static function pullRequest(int $number, string $body, string $author = 'ana'): ListedIssue
    {
        return new ListedIssue($number, 'Pull '.$number, $body, new IssueLabels(), $author, self::moment('2026-11-10T10:00:00Z'), null, true);
    }

    private static function moment(string $text): DateTimeImmutable
    {
        $moment = DateTimeImmutable::createFromFormat(self::MOMENT, $text, new DateTimeZone('UTC'));

        return false === $moment ? throw new LogicException('Not a moment: '.$text) : $moment;
    }
}
