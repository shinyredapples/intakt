<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\Ai\Contract\AiServices;
use LogicException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\AI\AiBundle\AiBundle;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Filesystem\Path;

// Covers nothing because the behavior is in config/packages/ai.php, which is not a class.
#[CoversNothing]
final class SecretsDirectoryConfigTest extends TestCase
{
    private const string MOVED = '/secrets-of-a-test';

    /** @var array<string, array{server: mixed, env: mixed, process: string|false}> */
    private array $saved = [];

    public function testAnInstallOutsideTheTestEnvironmentKeepsItsVaultsInConfigSecretsWhateverTheVariableSays(): void
    {
        self::assertSame(dirname(__DIR__, 2).'/config/secrets', $this->secretsDirectoryIn('prod'));
    }

    public function testTheTestEnvironmentKeepsItsVaultsWhereTheVariableSays(): void
    {
        self::assertSame(sys_get_temp_dir().self::MOVED, $this->secretsDirectoryIn('test'));
    }

    public function testTheTestEnvironmentWithoutTheVariableKeepsItsVaultsInConfigSecrets(): void
    {
        unset($_SERVER['INTAKT_SECRETS_DIR']);

        self::assertSame(dirname(__DIR__, 2).'/config/secrets', $this->secretsDirectoryIn('test'));
    }

    protected function setUp(): void
    {
        $temporary = sys_get_temp_dir();
        // Because ai.php also reads the projects file, the vault and the kept key, each points at a path that does not exist, so the test never reads the operator's own.
        $values = ['INTAKT_SECRETS_DIR' => $temporary.self::MOVED, 'INTAKT_CONFIG' => $temporary.'/missing-projects.yaml', 'INTAKT_PROVIDER_KEY_DIR' => $temporary.'/missing-kept-key', 'APP_RUNTIME_ENV' => 'missing-vault', 'SYMFONY_DECRYPTION_SECRET' => null];
        foreach ($values as $name => $value) {
            $this->saved[$name] = ['server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null, 'process' => getenv($name)];
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
            if (null !== $value) {
                $_SERVER[$name] = $value;
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $saved) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
            if (null !== $saved['server']) {
                $_SERVER[$name] = $saved['server'];
            }
            if (null !== $saved['env']) {
                $_ENV[$name] = $saved['env'];
            }
            if (false !== $saved['process']) {
                putenv($name.'='.$saved['process']);
            }
        }
    }

    // Canonical, because the config names the folder relative to its own.
    private function secretsDirectoryIn(string $environment): string
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new AiBundle()->getContainerExtension() ?? throw new LogicException('The AI bundle has no extension.'));
        $container->registerExtension(new McpBundle()->getContainerExtension() ?? throw new LogicException('The MCP bundle has no extension.'));
        new PhpFileLoader($container, new FileLocator(dirname(__DIR__, 2).'/config/packages'), $environment)->load('ai.php');

        return Path::canonicalize($container->getParameter(AiServices::SECRETS_DIRECTORY));
    }
}
