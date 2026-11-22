<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Infrastructure\CodexEffort;
use Intakt\Core\Ai\Infrastructure\CodexLogin;
use Intakt\Core\Ai\Infrastructure\CodexLogins;
use Intakt\Core\Ai\Infrastructure\CodexSettings;
use Intakt\Core\Ai\Infrastructure\CodexTokenRefresh;
use Intakt\Core\Ai\Infrastructure\IsolatedCodexCli;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Codex\Codex;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(IsolatedCodexCli::class)]
#[UsesClass(CodexEffort::class)]
#[UsesClass(CodexLogin::class)]
#[UsesClass(CodexLogins::class)]
#[UsesClass(CodexSettings::class)]
#[UsesClass(CodexTokenRefresh::class)]
final class IsolatedCodexCliTest extends TestCase
{
    private const string INHERITED = 'INTAKT_TEST_INHERITED_CREDENTIAL';

    // Answers in the shape of `codex exec --json`, so the bridge reads what the call saw as the answer's text.
    public const string FAKE_CLI = <<<'PHP_WRAP'
    #!/usr/bin/env php
    <?php
    $login = getenv('CODEX_HOME').'/auth.json';
    $seen = ['arguments' => array_slice($argv, 1), 'cwd' => getcwd(), 'environment' => getenv(), 'login' => is_file($login) ? sprintf('%o', fileperms($login) & 0777) : null, 'writable' => is_writable($login)];
    echo json_encode(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => json_encode($seen)]]), PHP_EOL;

    PHP_WRAP;

    private string $root;

    private string $path;

    public function testTheCliRunsInTheAccountsCallHomeWithAReadOnlyLogin(): void
    {
        $seen = $this->runOf($this->cli());

        $home = $this->root.'/codex/homes/team';
        self::assertSame([$home, $home, $home], [$seen['cwd'], $seen['environment']['CODEX_HOME'], $seen['environment']['HOME']]);
        self::assertSame(['400', false], [$seen['login'], $seen['writable']]);
    }

    public function testTheCliGetsNoValueOfIntaktsEnvironmentButItsPath(): void
    {
        $seen = $this->runOf($this->cli());

        self::assertSame('', $seen['environment'][self::INHERITED]);
        self::assertSame($this->root.'/bin:'.$this->path, $seen['environment']['PATH']);
    }

    public function testThePinnedModelAndEffortWinOverTheCallersOptions(): void
    {
        $seen = $this->runOf($this->cli(), ['model' => 'other', 'config' => ['model_reasoning_effort="minimal"']]);

        $model = $this->indexOf('--model', $seen['arguments']);
        self::assertSame(['--model', 'gpt-5.3-codex', '--config', 'web_search="disabled"', '--config', 'model_reasoning_effort="high"'], array_slice($seen['arguments'], $model, 6));
        self::assertNotContains('other', $seen['arguments']);
        self::assertNotContains('model_reasoning_effort="minimal"', $seen['arguments']);
    }

    public function testTheCliRunsWithoutItsToolsWebSearchSavedSessionOrUserConfig(): void
    {
        $arguments = $this->runOf($this->cli())['arguments'];

        foreach (['shell_tool', 'unified_exec', 'apps', 'plugins', 'browser_use', 'in_app_browser', 'computer_use', 'multi_agent', 'image_generation', 'view_image', 'hooks'] as $feature) {
            self::assertSame('--disable', $arguments[$this->indexOf($feature, $arguments) - 1]);
        }

        self::assertContains('web_search="disabled"', $arguments);
        self::assertSame([], array_values(array_diff(['--ephemeral', '--ignore-user-config', '--ignore-rules', '--skip-git-repo-check'], $arguments)));
    }

    public function testACallerCannotLiftTheConfinement(): void
    {
        $arguments = $this->runOf($this->cli(), ['sandbox' => 'danger-full-access', 'disable' => [], 'config' => ['web_search="live"']])['arguments'];

        self::assertSame('read-only', $arguments[$this->indexOf('--sandbox', $arguments) + 1]);
        self::assertNotContains('danger-full-access', $arguments);
        self::assertNotContains('web_search="live"', $arguments);
        self::assertContains('shell_tool', $arguments);
    }

    public function testACallerCannotAddAFlagThroughItsOptions(): void
    {
        $arguments = $this->runOf($this->cli(), ['dangerously_bypass_approvals_and_sandbox' => true, 'add_dir' => '/', 'cd' => '/', 'profile' => 'open'])['arguments'];

        self::assertSame([], array_values(array_intersect(['--dangerously-bypass-approvals-and-sandbox', '--add-dir', '--cd', '--profile'], $arguments)));
    }

    public function testACallerCannotAddAFlagThroughItsPayload(): void
    {
        $arguments = $this->runOf($this->cli(), [], ['prompt' => 'Answer with OK.', 'system_prompt' => 'Be brief.', 'add_dir' => '/'])['arguments'];

        self::assertNotContains('--add-dir', $arguments);
        self::assertSame("[System]\nBe brief.\n\n[User]\nAnswer with OK.", array_last($arguments));
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-codex-cli-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->root.'/bin/codex', self::FAKE_CLI);
        chmod($this->root.'/bin/codex', 0o700);
        new Filesystem()->dumpFile($this->root.'/codex/logins/team/auth.json', CodexLoginTest::json('2026-11-01T00:00:00Z'));
        $this->path = (string) getenv('PATH');
        putenv('PATH='.$this->root.'/bin:'.$this->path);
        putenv(self::INHERITED.'=inherited');
        $_ENV[self::INHERITED] = 'inherited';
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->path);
        putenv(self::INHERITED);
        unset($_ENV[self::INHERITED]);
        new Filesystem()->remove($this->root);
    }

    /**
     * @param list<string> $arguments
     */
    private function indexOf(string $argument, array $arguments): int
    {
        $index = array_search($argument, $arguments, true);
        self::assertIsInt($index);

        return $index;
    }

    private function cli(): IsolatedCodexCli
    {
        $logins = new CodexLogins($this->root.'/codex', new CodexTokenRefresh(new MockHttpClient(), new MockClock('2026-11-02 12:00:00', 'UTC')), new LockFactory(new InMemoryStore()));

        return new IsolatedCodexCli($logins, new CodexSettings('gpt-5.3-codex', CodexEffort::High), 'team');
    }

    /**
     * @param array<string, mixed>        $options
     * @param array<string, mixed>|string $payload
     *
     * @return array{arguments: list<string>, cwd: string, environment: array<string, string>, login: ?string, writable: bool}
     */
    private function runOf(IsolatedCodexCli $cli, array $options = [], array|string $payload = 'Answer with OK.'): array
    {
        $data = $cli->request(new Codex('gpt-5.3-codex'), $payload, $options)->getData();
        $item = $data['item'] ?? null;
        self::assertIsArray($item);
        $text = $item['text'] ?? null;
        self::assertIsString($text);

        /** @var array{arguments: list<string>, cwd: string, environment: array<string, string>, login: ?string, writable: bool} $seen */
        $seen = json_decode($text, true, flags: JSON_THROW_ON_ERROR);

        return $seen;
    }
}
