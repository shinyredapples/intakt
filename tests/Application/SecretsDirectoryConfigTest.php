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

// Covers nothing because the behavior is in config/packages/ai.php, which is not a class.
#[CoversNothing]
final class SecretsDirectoryConfigTest extends TestCase
{
    private const string MOVED = '/secrets-of-a-test';

    /** @var array{server: mixed, env: mixed, process: string|false} */
    private array $saved;

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
        $this->saved = ['server' => $_SERVER['INTAKT_SECRETS_DIR'] ?? null, 'env' => $_ENV['INTAKT_SECRETS_DIR'] ?? null, 'process' => getenv('INTAKT_SECRETS_DIR')];
        unset($_ENV['INTAKT_SECRETS_DIR']);
        putenv('INTAKT_SECRETS_DIR');
        $_SERVER['INTAKT_SECRETS_DIR'] = sys_get_temp_dir().self::MOVED;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['INTAKT_SECRETS_DIR'], $_ENV['INTAKT_SECRETS_DIR']);
        putenv('INTAKT_SECRETS_DIR');
        if (null !== $this->saved['server']) {
            $_SERVER['INTAKT_SECRETS_DIR'] = $this->saved['server'];
        }
        if (null !== $this->saved['env']) {
            $_ENV['INTAKT_SECRETS_DIR'] = $this->saved['env'];
        }
        if (false !== $this->saved['process']) {
            putenv('INTAKT_SECRETS_DIR='.$this->saved['process']);
        }
    }

    private function secretsDirectoryIn(string $environment): mixed
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new AiBundle()->getContainerExtension() ?? throw new LogicException('The AI bundle has no extension.'));
        $container->registerExtension(new McpBundle()->getContainerExtension() ?? throw new LogicException('The MCP bundle has no extension.'));
        new PhpFileLoader($container, new FileLocator(dirname(__DIR__, 2).'/config/packages'), $environment)->load('ai.php');

        return $container->getParameter(AiServices::SECRETS_DIRECTORY);
    }
}
