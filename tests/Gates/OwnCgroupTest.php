<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\OwnCgroup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OwnCgroup::class)]
final class OwnCgroupTest extends TestCase
{
    public function testAFileTheCgroupDoesNotHaveIsEmpty(): void
    {
        self::assertSame('', OwnCgroup::file('no-such-limit'));
    }

    public function testALimitFileOfTheOwnCgroupIsRead(): void
    {
        $cgroup = (string) @file_get_contents('/proc/self/cgroup');

        if (1 !== preg_match('/^0::(\/.*)$/m', $cgroup, $path) || !is_readable('/sys/fs/cgroup'.rtrim($path[1], '/').'/cgroup.type')) {
            self::assertSame('', OwnCgroup::file('cgroup.type'));

            return;
        }

        self::assertSame(file_get_contents('/sys/fs/cgroup'.rtrim($path[1], '/').'/cgroup.type'), OwnCgroup::file('cgroup.type'));
    }

    public function testTheOwnCgroupComesFirstThenEachParentUpToTheRoot(): void
    {
        $cgroup = (string) @file_get_contents('/proc/self/cgroup');

        if (1 !== preg_match('/^0::(\/.*)$/m', $cgroup, $path)) {
            self::assertSame([], OwnCgroup::fileAndAncestors('cgroup.type'));

            return;
        }

        $files = OwnCgroup::fileAndAncestors('cgroup.type');

        self::assertCount(substr_count(rtrim($path[1], '/'), '/'), $files);
        self::assertSame(OwnCgroup::file('cgroup.type'), $files[0] ?? '');
    }
}
