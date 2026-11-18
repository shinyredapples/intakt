<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Symfony\Component\Filesystem\Filesystem;

// Read from files, because a recorded answer is named by its path in the pull request that proves the reviewer handles it.
final readonly class RecordedAnswer
{
    public const string VERDICT_ONLY_CHANGES = 'verdict-only-changes.json';
    public const string VERDICT_ONLY_BLOCKED = 'verdict-only-blocked.json';
    public const string RETRY_WITH_A_FINDING = 'retry-with-a-finding.json';
    public const string APPROVE = 'approve.json';

    public static function named(string $file): string
    {
        return trim(new Filesystem()->readFile(dirname(__DIR__, 3).'/Fixtures/ReviewerAnswers/'.$file));
    }
}
