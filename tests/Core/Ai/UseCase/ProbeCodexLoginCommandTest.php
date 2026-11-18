<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\UseCase;

use Intakt\Core\Ai\Infrastructure\CodexEffort;
use Intakt\Core\Ai\Infrastructure\CodexLogin;
use Intakt\Core\Ai\Infrastructure\CodexLogins;
use Intakt\Core\Ai\Infrastructure\CodexLoginUnusable;
use Intakt\Core\Ai\Infrastructure\CodexSettings;
use Intakt\Core\Ai\Infrastructure\CodexTokenRefresh;
use Intakt\Core\Ai\Infrastructure\IsolatedCodexCli;
use Intakt\Core\Ai\UseCase\ProbeCodexLogin\ProbeCodexLoginCommand;
use Intakt\Tests\Core\Ai\Infrastructure\CodexLoginTest;
use Intakt\Tests\Core\Ai\Infrastructure\IsolatedCodexCliTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(ProbeCodexLoginCommand::class)]
#[UsesClass(CodexEffort::class)]
#[UsesClass(CodexLogin::class)]
#[UsesClass(CodexLogins::class)]
#[UsesClass(CodexLoginUnusable::class)]
#[UsesClass(CodexSettings::class)]
#[UsesClass(CodexTokenRefresh::class)]
#[UsesClass(IsolatedCodexCli::class)]
final class ProbeCodexLoginCommandTest extends TestCase
{
    private string $root;

    private string $path;

    public function testTheProbeShowsThePinnedModelAndEffortAndTheAnswer(): void
    {
        new Filesystem()->dumpFile($this->root.'/codex/logins/team/auth.json', CodexLoginTest::json('2026-11-01T00:00:00Z'));

        $tester = $this->execute(['model' => 'gpt-5.3-codex', 'effort' => 'high'], 'team');

        $output = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/Model\s+gpt-5\.3-codex/', $output);
        self::assertMatchesRegularExpression('/Effort\s+high/', $output);
        self::assertStringContainsString('"--model","gpt-5.3-codex","--config","web_search=\"disabled\"","--config","model_reasoning_effort=\"high\""', (string) preg_replace('/\s+/', '', $output));
    }

    public function testWithoutACodexSectionTheProbeSaysWhatToConfigure(): void
    {
        $tester = $this->execute(null, 'team');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('set codex.model and codex.effort', $tester->getDisplay());
    }

    public function testAnAccountWithoutALoginFailsTheProbe(): void
    {
        $tester = $this->execute(['model' => 'gpt-5.3-codex', 'effort' => 'high'], 'team');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('has no Codex login', $tester->getDisplay());
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-codex-probe-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->root.'/bin/codex', IsolatedCodexCliTest::FAKE_CLI);
        chmod($this->root.'/bin/codex', 0o700);
        $this->path = (string) getenv('PATH');
        putenv('PATH='.$this->root.'/bin:'.$this->path);
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->path);
        new Filesystem()->remove($this->root);
    }

    /**
     * @param array{model: non-empty-string, effort: string}|null $codex
     */
    private function execute(?array $codex, string $account): CommandTester
    {
        $logins = new CodexLogins($this->root.'/codex', new CodexTokenRefresh(new MockHttpClient(), new MockClock('2026-11-02 12:00:00', 'UTC')), new LockFactory(new InMemoryStore()));
        $application = new Application();
        $application->addCommand(new ProbeCodexLoginCommand($logins, $codex));
        $tester = new CommandTester($application->find('intakt:codex:probe'));
        $tester->execute(['account' => $account]);

        return $tester;
    }
}
