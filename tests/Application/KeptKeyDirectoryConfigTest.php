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
final class KeptKeyDirectoryConfigTest extends TestCase
{
    private const string MOVED = '/kept-key-of-a-test';

    /** @var array{server: mixed, env: mixed, process: string|false} */
    private array $saved;

    public function testAnInstallOutsideTheTestEnvironmentKeepsItsKeyInItsDataFolderWhateverTheVariableSays(): void
    {
        self::assertSame(dirname(__DIR__, 2).'/var/data', $this->keptKeyDirectoryIn('prod'));
    }

    public function testTheTestEnvironmentKeepsItsKeyWhereTheVariableSays(): void
    {
        self::assertSame(sys_get_temp_dir().self::MOVED, $this->keptKeyDirectoryIn('test'));
    }

    protected function setUp(): void
    {
        $this->saved = ['server' => $_SERVER['INTAKT_PROVIDER_KEY_DIR'] ?? null, 'env' => $_ENV['INTAKT_PROVIDER_KEY_DIR'] ?? null, 'process' => getenv('INTAKT_PROVIDER_KEY_DIR')];
        $_SERVER['INTAKT_PROVIDER_KEY_DIR'] = sys_get_temp_dir().self::MOVED;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['INTAKT_PROVIDER_KEY_DIR'], $_ENV['INTAKT_PROVIDER_KEY_DIR']);
        putenv('INTAKT_PROVIDER_KEY_DIR');
        if (null !== $this->saved['server']) {
            $_SERVER['INTAKT_PROVIDER_KEY_DIR'] = $this->saved['server'];
        }
        if (null !== $this->saved['env']) {
            $_ENV['INTAKT_PROVIDER_KEY_DIR'] = $this->saved['env'];
        }
        if (false !== $this->saved['process']) {
            putenv('INTAKT_PROVIDER_KEY_DIR='.$this->saved['process']);
        }
    }

    private function keptKeyDirectoryIn(string $environment): mixed
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new AiBundle()->getContainerExtension() ?? throw new LogicException('The AI bundle has no extension.'));
        $container->registerExtension(new McpBundle()->getContainerExtension() ?? throw new LogicException('The MCP bundle has no extension.'));
        new PhpFileLoader($container, new FileLocator(dirname(__DIR__, 2).'/config/packages'), $environment)->load('ai.php');

        return $container->getParameter(AiServices::KEPT_KEY_DIRECTORY);
    }
}
