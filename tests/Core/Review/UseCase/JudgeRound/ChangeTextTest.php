<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Infrastructure\DiffPaths;
use Intakt\Core\Review\UseCase\JudgeRound\ChangeText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangeText::class)]
#[UsesClass(DiffPaths::class)]
#[UsesClass(Checkout::class)]
final class ChangeTextTest extends TestCase
{
    private const string CHECKOUT = __DIR__.'/checkout';

    public function testTheTextHoldsTheDiffAndEachTouchedFileAsItIsInTheCheckout(): void
    {
        $diff = "diff --git a/src/report.txt b/src/report.txt\n+one line\n";

        $text = new ChangeText(new DiffPaths())->of(self::CHECKOUT, $diff);

        self::assertStringContainsString('+one line', $text);
        self::assertStringContainsString('helperOutsideTheHunk', $text);
    }

    public function testAFileTheChangeDeletedLeavesTheDiffAlone(): void
    {
        $diff = "diff --git a/src/gone.txt b/src/gone.txt\ndeleted file mode 100644\n-removedName\n";

        self::assertSame($diff, new ChangeText(new DiffPaths())->of(self::CHECKOUT, $diff));
    }

    public function testAPathOutsideTheCheckoutIsNeverRead(): void
    {
        $diff = "diff --git a/../ChangeTextTest.php b/../ChangeTextTest.php\n+x\n";

        self::assertSame($diff, new ChangeText(new DiffPaths())->of(self::CHECKOUT, $diff));
    }

    public function testADirectoryTheDiffNamesIsNotReadAsAFile(): void
    {
        $diff = "diff --git a/src b/src\n+x\n";

        self::assertSame($diff, new ChangeText(new DiffPaths())->of(self::CHECKOUT, $diff));
    }

    public function testAFileThatCannotBeReadLeavesTheDiffAlone(): void
    {
        $root = sys_get_temp_dir().'/change-text-'.bin2hex(random_bytes(4));
        mkdir($root);
        file_put_contents($root.'/locked.txt', 'lockedName');
        chmod($root.'/locked.txt', 0o000);
        $diff = "diff --git a/locked.txt b/locked.txt\n+x\n";

        try {
            if (is_readable($root.'/locked.txt')) {
                self::markTestSkipped('This user reads a file without permissions.');
            }

            self::assertSame($diff, new ChangeText(new DiffPaths())->of($root, $diff));
        } finally {
            unlink($root.'/locked.txt');
            rmdir($root);
        }
    }

    public function testACheckoutThatIsGoneLeavesTheDiffAlone(): void
    {
        $diff = "diff --git a/src/report.txt b/src/report.txt\n+x\n";

        self::assertSame($diff, new ChangeText(new DiffPaths())->of(self::CHECKOUT.'/missing', $diff));
    }
}
