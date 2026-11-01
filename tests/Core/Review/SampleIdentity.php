<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review;

use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\ReviewTerms;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;

final class SampleIdentity
{
    public const string HEAD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    public const string NEW_HEAD = 'cccccccccccccccccccccccccccccccccccccccc';
    private const string BASE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string CHECK_COMMAND = 'bin/check --fast';

    public static function of(string $task, Panel $panel, string $head = self::HEAD): VerdictIdentity
    {
        return new VerdictIdentity(new CommitRange(new CommitId(self::BASE), new CommitId($head)), ReviewTerms::of($task, self::CHECK_COMMAND, $panel));
    }

    // For a test that never compares identities, so its terms need not match any task or panel.
    public static function any(): VerdictIdentity
    {
        return VerdictIdentity::fromRecorded(implode('.', [self::HEAD, self::BASE, ...array_fill(0, 3, str_repeat('0', 64))]));
    }
}
