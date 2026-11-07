<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class UpstreamIssueExampleTest extends TestCase
{
    public function testTheFailoverIssueExamplesRunAndShowTheBugs(): void
    {
        $root = dirname(__DIR__, 2);
        $issue = file_get_contents($root.'/upstream/ai-failover-platform-retries-a-failed-platform/ISSUE.md');
        self::assertIsString($issue);
        self::assertSame(2, preg_match_all('/```php\n(.*?)```/s', $issue, $blocks));

        $process = new Process([PHP_BINARY, '-r', 'require "vendor/autoload.php"; '.implode("\n", $blocks[1])], $root);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        self::assertSame("2 2\nFailingPlatform, FailingPlatform, WorkingPlatform", trim($process->getOutput()));
    }
}
