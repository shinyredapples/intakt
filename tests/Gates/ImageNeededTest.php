<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversNothing]
final class ImageNeededTest extends SandboxTestCase
{
    private const string SCRIPT = __DIR__.'/../../.github/ci/image-needed.sh';

    /** @return iterable<string, array{list<string>, string}> */
    public static function changes(): iterable
    {
        yield 'a docs-only change' => [['docs/user/guide.md'], 'no'];
        yield 'a tests-only change' => [['tests/Core/OneTest.php'], 'no'];
        yield 'an upstream-only change' => [['upstream/library/report.md'], 'no'];
        yield 'a change under docs, tests and upstream' => [['docs/a.md', 'tests/OneTest.php', 'upstream/b.md'], 'no'];
        yield 'a mixed change' => [['docs/a.md', 'src/Core/Service.php'], 'yes'];
        yield 'a change under src' => [['src/Core/Service.php'], 'yes'];
        yield 'a change under .github' => [['.github/workflows/ci.yml'], 'yes'];
        yield 'a root file named like an excluded folder' => [['docs.md'], 'yes'];
        yield 'an excluded folder name deeper down' => [['src/docs/a.md'], 'yes'];
    }

    /** @param list<string> $paths */
    #[DataProvider('changes')]
    public function testTheAnswerSaysWhetherTheChangeNeedsTheImage(array $paths, string $expected): void
    {
        $sandbox = $this->startedSandbox();

        foreach ($paths as $path) {
            $sandbox->write($path, "changed\n");
        }
        $sandbox->commitAll('test: change');

        self::assertSame($expected, $this->answer($sandbox, 'HEAD~1 HEAD'));
    }

    public function testRenamingAFileOutOfDocsIntoSrcNeedsTheImage(): void
    {
        $sandbox = $this->startedSandbox();
        $sandbox->write('docs/moved.md', "moved\n");
        $sandbox->commitAll('docs: add the file');

        rename($sandbox->root.'/docs/moved.md', $sandbox->root.'/src/moved.md');
        $sandbox->commitAll('refactor: move the file');

        self::assertSame('yes', $this->answer($sandbox, 'HEAD~1 HEAD'));
    }

    public function testRenamingAFileOutOfSrcIntoDocsNeedsTheImage(): void
    {
        $sandbox = $this->startedSandbox();
        mkdir($sandbox->root.'/docs');

        rename($sandbox->root.'/src/Core/Service.php', $sandbox->root.'/docs/Service.php');
        $sandbox->commitAll('docs: move the file');

        self::assertSame('yes', $this->answer($sandbox, 'HEAD~1 HEAD'));
    }

    public function testAnEmptyChangeNeedsTheImage(): void
    {
        $sandbox = $this->startedSandbox();

        self::assertSame('yes', $this->answer($sandbox, 'HEAD HEAD'));
    }

    public function testAWrongArgumentCountIsAUsageError(): void
    {
        $result = $this->startedSandbox()->runScript(escapeshellarg(self::SCRIPT).' HEAD');

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('usage:', $result->errors);
    }

    public function testAnUnknownRevisionFailsInsteadOfAnsweringNo(): void
    {
        $result = $this->startedSandbox()->runScript(escapeshellarg(self::SCRIPT).' nonexistent HEAD');

        self::assertFalse($result->succeeded());
        self::assertStringNotContainsString('no', trim($result->output));
    }

    private function startedSandbox(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->startRepository();
        $sandbox->write('src/Core/Service.php', "<?php\n");
        $sandbox->commitAll('chore: start');

        return $sandbox;
    }

    private function answer(Sandbox $sandbox, string $revisions): string
    {
        $result = $sandbox->runScript(escapeshellarg(self::SCRIPT).' '.$revisions);

        self::assertTrue($result->succeeded(), $result->output.$result->errors);

        return trim($result->output);
    }
}
