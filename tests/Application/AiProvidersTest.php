<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(Kernel::class)]
final class AiProvidersTest extends KernelTestCase
{
    private const array VARIABLES = [
        'ANTHROPIC_API_KEY',
        'OPENAI_API_KEY',
        'OPENAI_COMPATIBLE_BASE_URL',
        'OPENAI_COMPATIBLE_API_KEY',
    ];

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = $_SERVER[$name] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            null === $value ? $this->unsetVariable($name) : $_SERVER[$name] = $value;
        }

        parent::tearDown();
    }

    public function testEveryProviderWithItsKeysIsConfiguredAndNoneIsDisabled(): void
    {
        $container = $this->bootWith(self::VARIABLES);

        self::assertTrue($container->has('ai.platform.anthropic'));
        self::assertTrue($container->has('ai.platform.openai'));
        self::assertTrue($container->has('ai.platform.generic.openai_compatible'));
        self::assertSame([], $container->getParameter('intakt.ai.disabled_providers'));
    }

    public function testAProviderWithoutItsKeyIsDisabledWithAMessageAndTheKernelBoots(): void
    {
        $container = $this->bootWith(['ANTHROPIC_API_KEY', 'OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_API_KEY']);

        self::assertFalse($container->has('ai.platform.openai'));
        self::assertTrue($container->has('ai.platform.anthropic'));
        self::assertSame(
            ['openai' => 'Provider "openai" is disabled: set OPENAI_API_KEY to enable it.'],
            $container->getParameter('intakt.ai.disabled_providers'),
        );
    }

    public function testAnEmptyKeyCountsAsMissing(): void
    {
        $_SERVER['OPENAI_API_KEY'] = '';

        $container = $this->bootWith(['ANTHROPIC_API_KEY', 'OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_API_KEY']);

        self::assertFalse($container->has('ai.platform.openai'));
    }

    public function testTheCompatibleEndpointNeedsBothItsAddressAndItsKey(): void
    {
        $container = $this->bootWith(['ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'OPENAI_COMPATIBLE_BASE_URL']);

        self::assertFalse($container->has('ai.platform.generic.openai_compatible'));
        self::assertSame(
            ['openai_compatible' => 'Provider "openai_compatible" is disabled: set OPENAI_COMPATIBLE_API_KEY to enable it.'],
            $container->getParameter('intakt.ai.disabled_providers'),
        );
    }

    public function testWithoutAnyKeyEveryProviderIsDisabledAndTheKernelStillBoots(): void
    {
        $container = $this->bootWith([]);

        self::assertSame(
            ['anthropic', 'openai', 'openai_compatible'],
            array_keys($container->getParameter('intakt.ai.disabled_providers')),
        );
    }

    public function testTheInMemoryPlatformAnswersWithoutAnyProvider(): void
    {
        $platform = MockPlatformFactory::createPlatform('a verdict');

        $result = $platform->invoke('any-model', new MessageBag(Message::ofUser('review this')))->asText();

        self::assertSame('a verdict', $result);
    }

    /**
     * @param list<string> $set
     */
    private function bootWith(array $set): ContainerInterface
    {
        foreach (self::VARIABLES as $name) {
            if (!\in_array($name, $set, true)) {
                $this->unsetVariable($name);
                continue;
            }

            $_SERVER[$name] = str_ends_with($name, 'BASE_URL') ? 'http://localhost:11434/v1' : 'test-value';
        }

        // The build decides on the variables, so every case needs a fresh container.
        $kernel = self::createKernel();
        (new Filesystem())->remove($kernel->getCacheDir());
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
