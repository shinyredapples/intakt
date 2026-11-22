<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\OwnCgroup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OwnCgroup::class)]
final class OwnCgroupTest extends TestCase
{
    private string $tree;

    /**
     * @var list<string>
     */
    private array $written = [];

    // Because in a private cgroup namespace the process sits at the root, whose limits are the container's own.
    public function testTheLimitsOfACgroupNamespaceRootAreRead(): void
    {
        $this->given('0::/', ['root/memory.max' => '4294967296', 'root/cpu.max' => '200000 100000']);

        self::assertSame('4294967296', $this->cgroup()->file('memory.max'));
        self::assertSame('200000 100000', $this->cgroup()->file('cpu.max'));
    }

    public function testTheOwnCgroupComesFirstThenEachParentUpToTheRoot(): void
    {
        $this->given('0::/a/b', ['root/a/b/memory.max' => 'own', 'root/a/memory.max' => 'parent', 'root/memory.max' => 'root']);

        self::assertSame(['own', 'parent', 'root'], $this->cgroup()->fileAndAncestors('memory.max'));
    }

    public function testAFileTheCgroupDoesNotHaveIsEmpty(): void
    {
        $this->given('0::/a/b', []);

        self::assertSame('', $this->cgroup()->file('memory.max'));
    }

    public function testOutsideCgroupV2NoFileIsRead(): void
    {
        $this->given('4:memory:/a/b', ['root/a/b/memory.max' => 'own']);

        self::assertSame([], $this->cgroup()->fileAndAncestors('memory.max'));
    }

    protected function setUp(): void
    {
        $this->tree = sys_get_temp_dir().'/intakt-cgroup-'.bin2hex(random_bytes(6));
        mkdir($this->tree.'/root/a/b', recursive: true);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), $this->written);

        foreach (['root/a/b', 'root/a', 'root', ''] as $directory) {
            rmdir($this->tree.'/'.$directory);
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function given(string $processCgroup, array $files): void
    {
        foreach (['cgroup' => $processCgroup."\n", ...$files] as $file => $contents) {
            file_put_contents($this->tree.'/'.$file, $contents);
            $this->written[] = $this->tree.'/'.$file;
        }
    }

    private function cgroup(): OwnCgroup
    {
        return new OwnCgroup($this->tree.'/cgroup', $this->tree.'/root');
    }
}
