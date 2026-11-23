<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\RequiredTools;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequiredTools::class)]
final class GateChildToolsTest extends TestCase
{
    private string|false $path;

    public function testRunningAGateInItsOwnProcessNeedsShAndSetsid(): void
    {
        putenv('PATH='.sys_get_temp_dir().'/intakt-no-such-folder');

        $missing = new RequiredTools(sys_get_temp_dir())->missingToRunEachInItsOwnProcess(['cs']);

        self::assertSame(array_map(RequiredTools::notFound(...), ['git', 'sh', 'setsid']), $missing);
    }

    public function testNothingIsMissingToRunAGateInItsOwnProcessWhenThePathHoldsTheTools(): void
    {
        self::assertSame([], new RequiredTools(sys_get_temp_dir())->missingToRunEachInItsOwnProcess(['cs']));
    }

    public function testNoCommandIsNeededToRunNoGate(): void
    {
        putenv('PATH='.sys_get_temp_dir().'/intakt-no-such-folder');

        self::assertSame([], new RequiredTools(sys_get_temp_dir())->missingToRunEachInItsOwnProcess([]));
    }

    protected function setUp(): void
    {
        $this->path = getenv('PATH');
    }

    protected function tearDown(): void
    {
        putenv(false === $this->path ? 'PATH' : 'PATH='.$this->path);
    }
}
