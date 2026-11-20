<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Core\Ai\Infrastructure\KeptProviderKeyFile;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiProviderCatalog;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Secrets\SodiumVault;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// Covers nothing because the behavior is in config/packages/ai.php, which is not a class; a process of its own because the warning goes to stderr, and serial because its vault is created under config/secrets/, which the workers share.
#[CoversNothing]
#[Group('serial')]
final class KeptKeyBuildWarningTest extends TestCase
{
    private const string VAULT_ENVIRONMENT = 'kept_key_build_warning_test';

    private const string COMPILE = <<<'PHP'
        require 'vendor/autoload.php';
        $container = new Symfony\Component\DependencyInjection\ContainerBuilder();
        $container->registerExtension(new Symfony\AI\AiBundle\AiBundle()->getContainerExtension());
        $container->registerExtension(new Symfony\AI\McpBundle\McpBundle()->getContainerExtension());
        new Symfony\Component\DependencyInjection\Loader\PhpFileLoader($container, new Symfony\Component\Config\FileLocator('config/packages'), 'test')->load('ai.php');
        PHP;

    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->vaultDirectory(), $this->keptKeyDirectory()]);
    }

    public function testAVaultLoaderThatFailsWhileTheContainerIsBuiltIsReportedOnStandardError(): void
    {
        $vault = new SodiumVault($this->vaultDirectory());
        $vault->generateKeys();
        $vault->seal('OPENAI_COMPATIBLE_BASE_URL', 'https://provider.example');
        $providers = new SymfonyAiProviderCatalog();
        new KeptProviderKeyFile($this->keptKeyDirectory(), $providers)->keep(ProviderKey::recognise('hf_abcdefgh12345678', $providers));

        $build = new Process([PHP_BINARY, '-r', self::COMPILE], dirname(__DIR__, 2));
        $build->mustRun(null, [
            'APP_RUNTIME_ENV' => self::VAULT_ENVIRONMENT,
            'INTAKT_PROVIDER_KEY_DIR' => $this->keptKeyDirectory(),
            // Base64 of the wrong length, so libsodium throws when the vault's loader reveals a secret.
            'SYMFONY_DECRYPTION_SECRET' => base64_encode('too short for a key pair'),
            'OPENAI_COMPATIBLE_BASE_URL' => false,
            'OPENAI_COMPATIBLE_API_KEY' => false,
            'SHELL_VERBOSITY' => false,
        ]);

        self::assertStringContainsString('Another env loader failed, so its variable counts as set and the kept key does not stand in for it.', $build->getErrorOutput());
        self::assertStringNotContainsString('provider.example', $build->getErrorOutput());
    }

    private function vaultDirectory(): string
    {
        return dirname(__DIR__, 2).'/config/secrets/'.self::VAULT_ENVIRONMENT;
    }

    private function keptKeyDirectory(): string
    {
        return sys_get_temp_dir().'/kept-key-build-warning-test';
    }
}
