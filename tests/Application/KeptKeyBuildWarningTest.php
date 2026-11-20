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
    // Starts with the prefix .gitignore leaves out for test vaults, so a run stopped before tearDown leaves no key git would offer to commit.
    private const string VAULT_ENVIRONMENT_PREFIX = 'ai_providers_test_kept_key_build_warning';

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
        $this->removeTheVaultsFolderWhenEmpty();
    }

    public function testAVaultLoaderThatFailsWhileTheContainerIsBuiltIsReportedOnStandardError(): void
    {
        $vault = new SodiumVault($this->vaultDirectory());
        $vault->generateKeys();
        $vault->seal('OPENAI_COMPATIBLE_BASE_URL', 'https://provider.example');
        $providers = new SymfonyAiProviderCatalog();
        new KeptProviderKeyFile($this->keptKeyDirectory(), $providers)->keep(ProviderKey::recognise('hf_abcdefgh12345678', $providers));

        $build = $this->build([
            'APP_RUNTIME_ENV' => $this->vaultEnvironment(),
            // Base64 of the wrong length, so libsodium throws when the vault's loader reveals a secret.
            'SYMFONY_DECRYPTION_SECRET' => base64_encode('too short for a key pair'),
        ]);

        self::assertStringContainsString('Another env loader failed, so its variable counts as set and the kept key does not stand in for it.', $build->getErrorOutput());
        self::assertStringNotContainsString('provider.example', $build->getErrorOutput());
    }

    public function testAnUnreadableKeptFileWhileTheContainerIsBuiltIsReportedOnStandardError(): void
    {
        new Filesystem()->dumpFile($this->keptKeyDirectory().'/provider-key.json', '{"provider": "huggingface", "key": "not a key of any form"}');

        $build = $this->build([]);

        self::assertStringContainsString('The kept provider key could not be read, so it is not used.', $build->getErrorOutput());
        self::assertStringNotContainsString('not a key of any form', $build->getErrorOutput());
    }

    /** @param array<string, string> $environment */
    private function build(array $environment): Process
    {
        $build = new Process([PHP_BINARY, '-r', self::COMPILE], dirname(__DIR__, 2));

        return $build->mustRun(null, [
            ...$environment,
            'INTAKT_PROVIDER_KEY_DIR' => $this->keptKeyDirectory(),
            'OPENAI_COMPATIBLE_BASE_URL' => false,
            'OPENAI_COMPATIBLE_API_KEY' => false,
            'SHELL_VERBOSITY' => false,
        ]);
    }

    private function vaultDirectory(): string
    {
        return dirname(__DIR__, 2).'/config/secrets/'.$this->vaultEnvironment();
    }

    // Because the framework keeps its vault env loader whenever config/secrets/ exists, so a folder left empty here would change every container built after this test.
    private function removeTheVaultsFolderWhenEmpty(): void
    {
        $vaults = dirname($this->vaultDirectory());
        if (is_dir($vaults) && [] === array_diff((array) scandir($vaults), ['.', '..'])) {
            rmdir($vaults);
        }
    }

    // Because every test worker has a TMPDIR of its own, each gets a vault folder of its own and none removes another's.
    private function vaultEnvironment(): string
    {
        return self::VAULT_ENVIRONMENT_PREFIX.'_'.hash('xxh128', sys_get_temp_dir());
    }

    // Under the worker's own TMPDIR, so no test reads or writes the install's kept key in var/data and none removes another worker's.
    private function keptKeyDirectory(): string
    {
        return sys_get_temp_dir().'/kept-key-build-warning-test';
    }
}
