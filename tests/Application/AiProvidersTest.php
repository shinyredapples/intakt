<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

// The behavior lives in config/packages/ai.php, which no class covers.
#[CoversNothing]
final class AiProvidersTest extends KernelTestCase
{
    private const array VARIABLES = [
        'ANTHROPIC_API_KEY',
        'OPENAI_API_KEY',
        'OPENAI_COMPATIBLE_BASE_URL',
        'OPENAI_COMPATIBLE_API_KEY',
    ];

    /** @var array<string, array{server: mixed, env: mixed, process: string|false}> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = [
                'server' => $_SERVER[$name] ?? null,
                'env' => $_ENV[$name] ?? null,
                'process' => getenv($name),
            ];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $before) {
            $this->unsetVariable($name);
            if (null !== $before['server']) {
                $_SERVER[$name] = $before['server'];
            }
            if (null !== $before['env']) {
                $_ENV[$name] = $before['env'];
            }
            if (false !== $before['process']) {
                putenv($name.'='.$before['process']);
            }
        }

        parent::tearDown();
        new Filesystem()->remove(self::createKernel()->getCacheDir());
    }

    public function testEveryProviderWithItsKeysIsConfiguredAndNoneIsDisabled(): void
    {
        $container = $this->bootWith($this->allSet());

        self::assertTrue($container->has('ai.platform.anthropic'));
        self::assertTrue($container->has('ai.platform.openai'));
        self::assertTrue($container->has('ai.platform.generic.openai_compatible'));
        self::assertSame([], $container->getParameter('intakt.ai.disabled_providers'));
    }

    public function testAProviderWithoutItsKeyIsDisabledWithAMessageAndTheKernelBoots(): void
    {
        $values = $this->allSet();
        unset($values['OPENAI_API_KEY']);

        $container = $this->bootWith($values);

        self::assertFalse($container->has('ai.platform.openai'));
        self::assertTrue($container->has('ai.platform.anthropic'));
        self::assertSame(
            ['openai' => 'Provider "openai" is disabled: set OPENAI_API_KEY to enable it.'],
            $container->getParameter('intakt.ai.disabled_providers'),
        );
    }

    public function testAnEmptyKeyCountsAsMissing(): void
    {
        $container = $this->bootWith([...$this->allSet(), 'OPENAI_API_KEY' => '']);

        self::assertFalse($container->has('ai.platform.openai'));
        self::assertArrayHasKey('openai', $container->getParameter('intakt.ai.disabled_providers'));
    }

    public function testTheCompatibleEndpointNeedsBothItsAddressAndItsKey(): void
    {
        $values = $this->allSet();
        unset($values['OPENAI_COMPATIBLE_API_KEY']);

        $container = $this->bootWith($values);

        self::assertFalse($container->has('ai.platform.generic.openai_compatible'));
        self::assertSame(
            ['openai_compatible' => 'Provider "openai_compatible" is disabled: set OPENAI_COMPATIBLE_API_KEY to enable it.'],
            $container->getParameter('intakt.ai.disabled_providers'),
        );
    }

    public function testWithoutAnyKeyEveryProviderIsDisabledAndTheKernelStillBoots(): void
    {
        $container = $this->bootWith([]);

        $disabled = $container->getParameter('intakt.ai.disabled_providers');

        self::assertIsArray($disabled);
        self::assertSame(['anthropic', 'openai', 'openai_compatible'], array_keys($disabled));
    }

    public function testAKeyInTheEnvironmentArrayWinsOverAnEmptyServerValue(): void
    {
        $_SERVER['OPENAI_API_KEY'] = '';
        $_ENV['OPENAI_API_KEY'] = 'test-value';

        $container = $this->bootWith(array_diff_key($this->allSet(), ['OPENAI_API_KEY' => true]), keep: ['OPENAI_API_KEY']);

        self::assertTrue($container->has('ai.platform.openai'));
    }

    /**
     * @return array<string, string>
     */
    private function allSet(): array
    {
        $values = [];
        foreach (self::VARIABLES as $name) {
            $values[$name] = str_ends_with($name, 'BASE_URL') ? 'http://localhost:11434/v1' : 'test-value';
        }

        return $values;
    }

    /**
     * @param array<string, string> $values variables to set; every other one is unset
     * @param list<string>          $keep   variables the test already set itself
     */
    private function bootWith(array $values, array $keep = []): ContainerInterface
    {
        foreach (self::VARIABLES as $name) {
            if (\in_array($name, $keep, true)) {
                continue;
            }

            $this->unsetVariable($name);
            if (\array_key_exists($name, $values)) {
                $_SERVER[$name] = $values[$name];
            }
        }

        // The build decides on the variables, so every case needs a fresh container; teardown removes it so later tests rebuild.
        new Filesystem()->remove(self::createKernel()->getCacheDir());
        self::ensureKernelShutdown();
        self::bootKernel();

        return self::getContainer();
    }

    private function unsetVariable(string $name): void
    {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    }
}
