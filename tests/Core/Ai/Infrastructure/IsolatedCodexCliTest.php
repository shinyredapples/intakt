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

    // Answers like `codex exec --json` with what the call saw: its arguments, environment, directory and login file.
    public const string FAKE_CLI = <<<'PHP_WRAP'
    #!/usr/bin/env php
    <?php
    $login = getenv('CODEX_HOME').'/auth.json';
    $seen = ['arguments' => array_slice($argv, 1), 'cwd' => getcwd(), 'environment' => getenv(), 'login' => is_file($login) ? sprintf('%o', fileperms($login) & 0777) : null, 'writable' => is_writable($login)];
    echo json_encode(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => json_encode($seen)]]), PHP_EOL;

    PHP_WRAP;

    private string $root;

    private string $path;

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

        self::assertSame(['--model', 'gpt-5.3-codex', '--config', 'model_reasoning_effort="high"'], array_slice($seen['arguments'], 2, 4));
    }

    private function cli(): IsolatedCodexCli
    {
        $logins = new CodexLogins($this->root.'/codex', new CodexTokenRefresh(new MockHttpClient()), new LockFactory(new InMemoryStore()), new MockClock('2026-11-02 12:00:00', 'UTC'));

        return new IsolatedCodexCli($logins, new CodexSettings('gpt-5.3-codex', CodexEffort::High), 'team');
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function runOf(IsolatedCodexCli $cli, array $options = []): array
    {
        $data = $cli->request(new Codex('gpt-5.3-codex'), 'Answer with OK.', $options)->getData();
        self::assertIsArray($data['item'] ?? null);

        /** @var array<string, mixed> */
        return json_decode((string) $data['item']['text'], true, flags: JSON_THROW_ON_ERROR);
    }
}
