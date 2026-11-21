<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Intakt\Tests\Journeys\Support\Fixtures\VanishedProcess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

#[CoversClass(ProcParents::class)]
#[UsesClass(ProcessEntry::class)]
final class ProcParentsTest extends TestCase
{
    // Above the largest PID Linux hands out (pid_max is at most 2^22), so no process has it.
    private const int NO_PROCESS = 4_194_305;

    public function testTheParentOfAStartedProcessIsTheProcessThatStartedIt(): void
    {
        $child = new Process(['sleep', '5']);
        $child->start();

        try {
            self::assertSame(getmypid(), new ProcParents()->parentOf((int) $child->getPid()));
        } finally {
            $child->stop(0);
        }
    }

    public function testAProcessWhoseNameHoldsSpacesAndParenthesesStillHasItsParentRead(): void
    {
        $filesystem = new Filesystem();
        $directory = $filesystem->tempnam(sys_get_temp_dir(), 'proc-');
        $filesystem->remove($directory);
        $filesystem->symlink((string) new ExecutableFinder()->find('sleep'), $directory.'/a (b) c');
        $child = new Process([$directory.'/a (b) c', '5']);
        $child->start();

        try {
            $this->waitUntilNamed((int) $child->getPid(), '(a (b) c)');

            self::assertSame(getmypid(), new ProcParents()->parentOf((int) $child->getPid()));
        } finally {
            $child->stop(0);
            $filesystem->remove($directory);
        }
    }

    public function testAPidThatNoProcessHasHasNoParent(): void
    {
        self::assertNull(new ProcParents()->parentOf(self::NO_PROCESS));
    }

    public function testAPidThatNoProcessHasHasNoStartTime(): void
    {
        self::assertNull(new ProcParents()->startOf(self::NO_PROCESS));
    }

    public function testAProcessEntryHoldsTheParentAndTheStartTimeOfTheProcess(): void
    {
        $child = new Process(['sleep', '5']);
        $child->start();

        try {
            $pid = (int) $child->getPid();
            $proc = new ProcParents();

            self::assertEquals(new ProcessEntry((int) getmypid(), (int) $proc->startOf($pid)), $proc->entryOf($pid));
        } finally {
            $child->stop(0);
        }
    }

    public function testAPidThatNoProcessHasHasNoEntry(): void
    {
        self::assertNull(new ProcParents()->entryOf(self::NO_PROCESS));
    }

    public function testAProcessStartedLaterHasALaterStartTime(): void
    {
        $child = new Process(['sleep', '5']);
        $child->start();

        try {
            $proc = new ProcParents();

            self::assertGreaterThanOrEqual((int) $proc->startOf((int) getmypid()), (int) $proc->startOf((int) $child->getPid()));
        } finally {
            $child->stop(0);
        }
    }

    public function testAProcessThatEndsBetweenFindingAndReadingItsEntryHasNoParentAndRaisesNoWarning(): void
    {
        stream_wrapper_register(VanishedProcess::SCHEME, VanishedProcess::class);

        try {
            self::assertNull(new ProcParents(VanishedProcess::SCHEME.'://proc')->parentOf(200));
        } finally {
            stream_wrapper_unregister(VanishedProcess::SCHEME);
        }
    }

    private function waitUntilNamed(int $pid, string $name): void
    {
        $clock = new MonotonicClock();

        for ($tries = 0; $tries < 100 && !str_contains((string) file_get_contents('/proc/'.$pid.'/stat'), $name); ++$tries) {
            $clock->sleep(0.01);
        }

        self::assertStringContainsString($name, (string) file_get_contents('/proc/'.$pid.'/stat'));
    }
}
