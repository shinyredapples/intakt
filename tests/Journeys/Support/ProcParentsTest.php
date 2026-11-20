<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

#[CoversClass(ProcParents::class)]
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

    private function waitUntilNamed(int $pid, string $name): void
    {
        for ($tries = 0; $tries < 100 && !str_contains((string) file_get_contents('/proc/'.$pid.'/stat'), $name); ++$tries) {
            usleep(10_000);
        }

        self::assertStringContainsString($name, (string) file_get_contents('/proc/'.$pid.'/stat'));
    }
}
