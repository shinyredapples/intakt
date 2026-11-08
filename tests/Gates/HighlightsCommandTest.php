<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class HighlightsCommandTest extends SandboxTestCase
{
    private const string COMMAND = __DIR__.'/../../bin/highlights';

    public function testTheHighlightsCommandIsExecutable(): void
    {
        self::assertTrue(is_executable(self::COMMAND));
    }

    public function testTheHighlightsCommandWritesTheAssembledPage(): void
    {
        $sandbox = $this->installedSandbox();

        $run = $sandbox->run(PHP_BINARY, 'bin/highlights');

        self::assertTrue($run->succeeded(), $run->errors);
        self::assertSame("# Highlights\n\n## Tooling\n\n| Feature | Status |\n|---|---|\n| Clock | done |\n", file_get_contents($sandbox->root.'/docs/highlights.md'));
    }

    public function testTheHighlightsCommandFailsWhenThePageCannotBeWritten(): void
    {
        $sandbox = $this->installedSandbox();
        mkdir($sandbox->root.'/docs/highlights.md');

        $run = $sandbox->run(PHP_BINARY, 'bin/highlights');

        self::assertFalse($run->succeeded());
        self::assertStringStartsWith('cannot write docs/highlights.md', $run->errors);
        self::assertStringNotContainsString('assembled', $run->output);
    }

    private function installedSandbox(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('bin/highlights', (string) file_get_contents(self::COMMAND));
        $sandbox->symlink('vendor', (string) realpath(__DIR__.'/../../vendor'));
        $sandbox->write('docs/highlights.d/layout.md', "# Highlights\n\n## Tooling\n\n| Feature | Status |\n|---|---|\n");
        $sandbox->write('docs/highlights.d/1-clock.md', "## Tooling\n| Clock | done |\n");

        return $sandbox;
    }
}
