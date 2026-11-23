<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Core\Ai\Infrastructure\KeptProviderKeyFile;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiProviderCatalog;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Secrets\SodiumVault;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// Covers nothing because the behavior is in config/packages/ai.php, which is not a class; a process of its own because the warning goes to stderr.
#[CoversNothing]
final class KeptKeyBuildWarningTest extends TestCase
{
    // A runtime environment of its own, so the vault folder is named for this test.
    private const string VAULT_ENVIRONMENT = 'ai_providers_test_kept_key_build_warning';

    // An empty project file under tests/, so the build never reads the install's config/intakt.yaml.
    private const string PROJECT_FILE = __DIR__.'/Fixtures/no-projects.yaml';

    // Prints the two settings it ran with, so each case can show that the build saw the test's own and not the operator's.
    private const string COMPILE = <<<'PHP'
        use Symfony\AI\AiBundle\AiBundle;
        use Symfony\AI\McpBundle\McpBundle;
        use Symfony\Component\Config\FileLocator;
        use Symfony\Component\DependencyInjection\ContainerBuilder;
        use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

        require 'vendor/autoload.php';
        $container = new ContainerBuilder();
        $container->registerExtension(new AiBundle()->getContainerExtension());
        $container->registerExtension(new McpBundle()->getContainerExtension());
        new PhpFileLoader($container, new FileLocator('config/packages'), 'test')->load('ai.php');
        echo getenv('INTAKT_CONFIG'), "\n", getenv('APP_RUNTIME_ENV');
        PHP;

    public function testAVaultLoaderThatFailsWhileTheContainerIsBuiltIsReportedOnStandardError(): void
    {
        $vault = new SodiumVault($this->vaultDirectory());
        $vault->generateKeys();
        $vault->seal('OPENAI_COMPATIBLE_BASE_URL', 'https://provider.example');
        $providers = new SymfonyAiProviderCatalog();
        new KeptProviderKeyFile($this->keptKeyDirectory(), $providers)->keep(ProviderKey::recognise('hf_abcdefgh12345678', $providers));

        // Base64 of the wrong length, so libsodium throws when the vault's loader reveals a secret.
        $build = $this->build(base64_encode('too short for a key pair'));

        self::assertStringContainsString('Another env loader failed, so its variable counts as set and the kept key does not stand in for it.', $build->getErrorOutput());
        self::assertStringNotContainsString('provider.example', $build->getErrorOutput());
        $this->assertTheBuildRanWithTheTestsOwnSettings($build);
    }

    public function testAnUnreadableKeptFileWhileTheContainerIsBuiltIsReportedOnStandardError(): void
    {
        new Filesystem()->dumpFile($this->keptKeyDirectory().'/provider-key.json', '{"provider": "huggingface", "key": "not a key of any form"}');

        $build = $this->build(false);

        self::assertStringContainsString('The kept provider key could not be read, so it is not used.', $build->getErrorOutput());
        self::assertStringNotContainsString('not a key of any form', $build->getErrorOutput());
        $this->assertTheBuildRanWithTheTestsOwnSettings($build);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->secretsDirectory(), $this->keptKeyDirectory()]);
    }

    // Under the worker's own TMPDIR, so the run leaves the checkout as it found it (the quality check records a pass only for a tree nothing changed in) and none removes another worker's vault.
    private function secretsDirectory(): string
    {
        return sys_get_temp_dir().'/kept-key-build-warning-test-secrets';
    }

    private function vaultDirectory(): string
    {
        return $this->secretsDirectory().'/'.self::VAULT_ENVIRONMENT;
    }

    // Under the worker's own TMPDIR, so no test reads or writes the install's kept key in var/data and none removes another worker's.
    private function keptKeyDirectory(): string
    {
        return sys_get_temp_dir().'/kept-key-build-warning-test';
    }

    // Every case builds through here, so none can read the operator's project file, vault or decryption key.
    private function build(string|false $decryptionSecret): Process
    {
        $build = new Process([PHP_BINARY, '-r', self::COMPILE], dirname(__DIR__, 2));

        return $build->mustRun(null, [
            'APP_RUNTIME_ENV' => self::VAULT_ENVIRONMENT,
            'INTAKT_CONFIG' => self::PROJECT_FILE,
            'SYMFONY_DECRYPTION_SECRET' => $decryptionSecret,
            'INTAKT_PROVIDER_KEY_DIR' => $this->keptKeyDirectory(),
            'INTAKT_SECRETS_DIR' => $this->secretsDirectory(),
            'OPENAI_COMPATIBLE_BASE_URL' => false,
            'OPENAI_COMPATIBLE_API_KEY' => false,
            'SHELL_VERBOSITY' => false,
        ]);
    }

    private function assertTheBuildRanWithTheTestsOwnSettings(Process $build): void
    {
        self::assertSame(self::PROJECT_FILE."\n".self::VAULT_ENVIRONMENT, $build->getOutput());
    }
}
