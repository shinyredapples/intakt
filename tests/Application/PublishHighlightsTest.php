<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class PublishHighlightsTest extends TestCase
{
    private const string COMMAND = __DIR__.'/../../bin/publish-highlights';

    private string $scratch;

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir().'/intakt-publish-highlights-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->scratch);
        $this->git($this->scratch, 'init', '--quiet', '--bare', '--initial-branch=main', 'origin.git');
        $this->git($this->scratch, 'clone', '--quiet', 'origin.git', 'main');
        new Filesystem()->dumpFile($this->scratch.'/main/README.md', "readme\n");
        $this->git($this->scratch.'/main', 'add', 'README.md');
        $this->commit($this->scratch.'/main', 'docs: start');
        $this->git($this->scratch.'/main', 'push', '--quiet', 'origin', 'main');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->scratch);
    }

    public function testTheFirstPublicationCreatesTheBranchWithOnlyThePageInOneCommit(): void
    {
        $this->publish("# Highlights\n");

        self::assertSame("# Highlights\n", $this->git($this->scratch.'/origin.git', 'show', 'highlights-page:highlights.md'));
        self::assertSame("highlights.md\n", $this->git($this->scratch.'/origin.git', 'ls-tree', '--name-only', 'highlights-page'));
        self::assertSame("1\n", $this->git($this->scratch.'/origin.git', 'rev-list', '--count', 'highlights-page'));
    }

    public function testALaterPublicationReplacesTheBranchWithOneCommitOfTheNewPage(): void
    {
        $this->publish("# Highlights\n");

        $this->publish("# Highlights\n\nnew row\n");

        self::assertSame("# Highlights\n\nnew row\n", $this->git($this->scratch.'/origin.git', 'show', 'highlights-page:highlights.md'));
        self::assertSame("highlights.md\n", $this->git($this->scratch.'/origin.git', 'ls-tree', '--name-only', 'highlights-page'));
        self::assertSame("1\n", $this->git($this->scratch.'/origin.git', 'rev-list', '--count', 'highlights-page'));
    }

    public function testPublishingLeavesMainUntouched(): void
    {
        $main = $this->git($this->scratch.'/origin.git', 'rev-parse', 'main');

        $this->publish("# Highlights\n");

        self::assertSame($main, $this->git($this->scratch.'/origin.git', 'rev-parse', 'main'));
    }

    private function publish(string $page): void
    {
        $checkout = $this->scratch.'/publish-'.bin2hex(random_bytes(4));
        $this->git($this->scratch, 'clone', '--quiet', 'origin.git', $checkout);
        new Filesystem()->dumpFile($this->scratch.'/page.md', $page);

        $run = new Process(['sh', self::COMMAND,$this->scratch.'/page.md'], $checkout, ['GIT_CONFIG_GLOBAL' => '/dev/null']);
        $run->run();

        self::assertTrue($run->isSuccessful(), $run->getErrorOutput());
    }

    private function commit(string $directory, string $message): void
    {
        $this->git($directory, '-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '--quiet', '--message', $message);
    }

    private function git(string $directory, string ...$arguments): string
    {
        $run = new Process(['git', ...$arguments], $directory, ['GIT_CONFIG_GLOBAL' => '/dev/null']);
        $run->mustRun();

        return $run->getOutput();
    }
}
