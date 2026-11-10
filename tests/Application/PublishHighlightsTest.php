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
    private string $scratch;

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir().'/intakt-publish-highlights-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->scratch.'/main/README.md', "readme\n");
        $this->git('main', 'init', '--quiet', '--initial-branch=main');
        $this->git('main', 'add', 'README.md');
        $this->git('main', '-c', 'user.name=T', '-c', 'user.email=t@example.com', 'commit', '--quiet', '-m', 'docs: start');
        $this->git('', 'clone', '--quiet', '--bare', 'main', 'origin.git');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->scratch);
    }

    public function testTheFirstPublicationCreatesTheBranchWithOnlyThePageInOneCommit(): void
    {
        $this->publish("# Highlights\n");

        $this->assertPublished("# Highlights\n");
    }

    public function testALaterPublicationReplacesTheBranchWithOneCommitOfTheNewPage(): void
    {
        $this->publish("# Highlights\n");

        $this->publish("# Highlights\n\nnew row\n");

        $this->assertPublished("# Highlights\n\nnew row\n");
    }

    public function testPublishingLeavesMainUntouched(): void
    {
        $main = $this->git('origin.git', 'rev-parse', 'main');

        $this->publish("# Highlights\n");

        self::assertSame($main, $this->git('origin.git', 'rev-parse', 'main'));
    }

    private function publish(string $page): void
    {
        $checkout = 'publish-'.bin2hex(random_bytes(4));
        $this->git('', 'clone', '--quiet', 'origin.git', $checkout);
        new Filesystem()->dumpFile($this->scratch.'/page.md', $page);

        new Process(['sh', __DIR__.'/../../bin/publish-highlights', $this->scratch.'/page.md'], $this->scratch.'/'.$checkout, ['GIT_CONFIG_GLOBAL' => '/dev/null'])->mustRun();
    }

    private function assertPublished(string $page): void
    {
        self::assertSame($page, $this->git('origin.git', 'show', 'highlights-page:highlights.md'));
        self::assertSame("highlights.md\n", $this->git('origin.git', 'ls-tree', '--name-only', 'highlights-page'));
        self::assertSame("1\n", $this->git('origin.git', 'rev-list', '--count', 'highlights-page'));
    }

    private function git(string $directory, string ...$arguments): string
    {
        return new Process(['git', ...$arguments], $this->scratch.'/'.$directory, ['GIT_CONFIG_GLOBAL' => '/dev/null'])->mustRun()->getOutput();
    }
}
