<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class UpstreamFixPatchTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function patches(): iterable
    {
        $patches = glob(dirname(__DIR__, 2).'/upstream/*/fix.patch');
        self::assertIsArray($patches);

        foreach ($patches as $patch) {
            yield basename(dirname($patch)) => [$patch];
        }
    }

    #[DataProvider('patches')]
    public function testGitAcceptsTheDateOfEachUpstreamFixPatch(string $patch): void
    {
        $contents = file_get_contents($patch);
        self::assertIsString($contents);
        if (1 !== preg_match('/^Date: (.+)$/m', $contents, $date)) {
            self::fail('The patch has no Date header.');
        }

        $process = new Process(
            ['git', '-c', 'user.name=Check', '-c', 'user.email=check@example.invalid', 'var', 'GIT_AUTHOR_IDENT'],
            env: ['GIT_AUTHOR_DATE' => $date[1]],
        );
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
}
