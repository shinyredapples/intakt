<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Contract;

use Intakt\Core\Ai\Contract\ConfiguredKey;
use Intakt\Core\Ai\Contract\KeyNotRecognised;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Plugin\Vendor\Contract\ProviderCatalog;
use Intakt\Plugin\Vendor\Contract\ProviderDescriptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(ProviderKey::class)]
#[CoversClass(KeyNotRecognised::class)]
#[UsesClass(ConfiguredKey::class)]
final class ProviderKeyTest extends TestCase
{
    public function testAKeyIsRecognisedAsTheProviderWhoseFormItFits(): void
    {
        self::assertSame('beta', ProviderKey::recognise('b_'.$this->body(), $this->catalog())->provider());
    }

    public function testSurroundingWhitespaceIsIgnored(): void
    {
        self::assertSame('alpha', ProviderKey::recognise('  a_'.$this->body()."\n", $this->catalog())->provider());
    }

    public function testAKeyOfNoListedFormIsRefused(): void
    {
        $this->expectException(KeyNotRecognised::class);

        ProviderKey::recognise('xx-'.$this->body(), $this->catalog());
    }

    public function testAnEmptyKeyIsRefused(): void
    {
        $this->expectException(KeyNotRecognised::class);

        ProviderKey::recognise('   ', $this->catalog());
    }

    public function testAKeyTwoListedFormsFitIsRefusedInsteadOfGoingToTheFirst(): void
    {
        $catalog = $this->catalog($this->descriptor('alpha', '/^a_\w{8,}$/D'), $this->descriptor('wide', '/^\w{8,}$/D'));

        $this->expectException(KeyNotRecognised::class);

        ProviderKey::recognise('a_'.$this->body(), $catalog);
    }

    public function testTheRefusalNamesTheListedProvidersButNotTheKey(): void
    {
        $key = 'xx-secretvalue'.$this->body();

        try {
            ProviderKey::recognise($key, $this->catalog());
            self::fail('The key should be refused.');
        } catch (KeyNotRecognised $refusal) {
            self::assertSame('The key has the form of no listed provider (Alpha, Beta).', $refusal->getMessage());
        }
    }

    public function testTheConfiguredStateShowsTheProviderAndOnlyTheLastFourCharacters(): void
    {
        $configured = ProviderKey::recognise('a_'.$this->body(), $this->catalog())->configured();

        self::assertEquals(new ConfiguredKey('alpha', '1234'), $configured);
    }

    public function testAShortKeyShowsFewerThanFourCharacters(): void
    {
        $configured = ProviderKey::recognise('b_abcdefgh', $this->catalog())->configured();

        self::assertSame('gh', $configured->lastCharacters);
    }

    public function testAKeyUnderFourCharactersShowsNoCharacters(): void
    {
        $configured = ProviderKey::recognise('abc', $this->catalog($this->descriptor('tiny', '/^\w{1,3}$/D')))->configured();

        self::assertEquals(new ConfiguredKey('tiny', ''), $configured);
    }

    public function testTheShortestKeyWithAShownPartShowsOneCharacter(): void
    {
        $configured = ProviderKey::recognise('abcd', $this->catalog($this->descriptor('tiny', '/^\w{1,4}$/D')))->configured();

        self::assertSame('d', $configured->lastCharacters);
    }

    public function testTheKeyDoesNotAppearInVarExportPrintROrVarDumpOutput(): void
    {
        $key = ProviderKey::recognise('a_'.$this->body('visiblepart'), $this->catalog());

        ob_start();
        var_dump($key);
        print_r($key);
        $output = ob_get_clean().var_export($key, true);

        self::assertStringNotContainsString('visiblepart', $output);
    }

    public function testTheKeyDoesNotAppearInATraceThatReceivedIt(): void
    {
        $caught = null;

        try {
            ProviderKey::recognise('xx-tracedvalue'.$this->body(), $this->catalog());
        } catch (Throwable $caught) {
        }

        self::assertStringNotContainsString('tracedvalue', (string) $caught?->getTraceAsString());
    }

    /**
     * @param non-empty-string $id
     * @param non-empty-string $pattern
     */
    private function descriptor(string $id, string $pattern): ProviderDescriptor
    {
        $descriptor = self::createStub(ProviderDescriptor::class);
        $descriptor->method('id')->willReturn($id);
        $descriptor->method('label')->willReturn(ucfirst($id));
        $descriptor->method('keyPattern')->willReturn($pattern);

        return $descriptor;
    }

    private function body(string $tail = '1234'): string
    {
        return str_repeat('0', 24).$tail;
    }

    private function catalog(ProviderDescriptor ...$descriptors): ProviderCatalog
    {
        $catalog = self::createStub(ProviderCatalog::class);
        $catalog->method('descriptors')->willReturn([] === $descriptors ? [$this->descriptor('alpha', '/^a_\w{8,}$/D'), $this->descriptor('beta', '/^b_\w{8,}$/D')] : array_values($descriptors));

        return $catalog;
    }
}
