<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Upstream;

use Intakt\Core\Upstream\Contract\ReproductionOutcome;
use Intakt\Core\Upstream\Contract\ReproductionStatus;
use Intakt\Core\Upstream\Infrastructure\ReproductionRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ReproductionRunner::class)]
#[UsesClass(ReproductionOutcome::class)]
#[UsesClass(ReproductionStatus::class)]
final class ReproductionRunnerTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    private string $folder = '';

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-runner-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    public function testATestThatRanAndAssertedPassesUnderTheNameOfItsFolder(): void
    {
        $this->givenReproduction('fixed-bug', '$this->assertTrue(true);');

        $outcome = $this->outcomeOf('fixed-bug');

        self::assertSame(['fixed-bug', ReproductionStatus::Passes], [$outcome->slug, $outcome->status]);
    }

    public function testAFailingTestStillFailsWithItsOutput(): void
    {
        $this->givenReproduction('open-bug', '$this->assertSame(1, 2);');

        $outcome = $this->outcomeOf('open-bug');

        self::assertSame(ReproductionStatus::StillFails, $outcome->status);
        self::assertStringContainsString('Failed asserting', $outcome->output);
    }

    public function testATestWithoutAnAssertionStillFails(): void
    {
        $this->givenReproduction('empty-bug', '');

        self::assertSame(ReproductionStatus::StillFails, $this->outcomeOf('empty-bug')->status);
    }

    public function testAFolderWithoutATestOrScriptStillFails(): void
    {
        new Filesystem()->dumpFile($this->folder.'/notes-only/REPORT.md', "# Report\n");

        self::assertSame(ReproductionStatus::StillFails, $this->outcomeOf('notes-only')->status);
    }

    public function testAScriptThatPrintsTheMarkerLastAndExitsWithZeroPasses(): void
    {
        $this->givenScript('fixed-script', "echo 'checked'.PHP_EOL.'REPRODUCTION PASSES'.PHP_EOL;");

        self::assertSame(ReproductionStatus::Passes, $this->outcomeOf('fixed-script')->status);
    }

    public function testAScriptThatPrintsTheMarkerButExitsWithAnErrorStillFails(): void
    {
        $this->givenScript('open-script', "echo 'REPRODUCTION PASSES'.PHP_EOL;\nexit(1);");

        self::assertSame(ReproductionStatus::StillFails, $this->outcomeOf('open-script')->status);
    }

    public function testAScriptThatExitsWithZeroWithoutTheMarkerStillFails(): void
    {
        $this->givenScript('unchecked-script', "echo 'ok';");

        self::assertSame(ReproductionStatus::StillFails, $this->outcomeOf('unchecked-script')->status);
    }

    public function testAHangingScriptStillFailsWithTheTimeout(): void
    {
        $this->givenScript('hanging-script', 'sleep(30);');

        $outcome = new ReproductionRunner(self::ROOT, 0.5)->run($this->folder.'/hanging-script');

        self::assertSame(ReproductionStatus::StillFails, $outcome->status);
        self::assertSame('Timed out after 0.5 seconds.', $outcome->output);
    }

    private function outcomeOf(string $slug): ReproductionOutcome
    {
        return new ReproductionRunner(self::ROOT)->run($this->folder.'/'.$slug);
    }

    private function givenReproduction(string $slug, string $body): void
    {
        $class = 'Repro'.bin2hex(random_bytes(4)).'Test';
        $source = "<?php\n\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class {$class} extends TestCase\n{\n    public function testIt(): void\n    {\n        {$body}\n    }\n}\n";

        new Filesystem()->dumpFile($this->folder.'/'.$slug.'/'.$class.'.php', $source);
    }

    private function givenScript(string $slug, string $body): void
    {
        new Filesystem()->dumpFile($this->folder.'/'.$slug.'/reproduce.php', "<?php\n\n{$body}\n");
    }
}
