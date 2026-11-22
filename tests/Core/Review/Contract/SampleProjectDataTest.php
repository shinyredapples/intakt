<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\ModelPrice;
use Intakt\Core\Review\Contract\SampleProjectData;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(SampleProjectData::class)]
#[UsesClass(ModelPrice::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
final class SampleProjectDataTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-sample-data-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testTheShippedFileNamesAModelAndAPriceForEveryProviderAndTier(): void
    {
        $data = new SampleProjectData();

        foreach ([Provider::Anthropic, Provider::OpenAi, Provider::OpenAiCompatible] as $provider) {
            foreach (Tier::cases() as $tier) {
                self::assertArrayHasKey('input', iterator_to_array($data->price($data->model($provider, $tier))));
            }
        }
        self::assertArrayHasKey('output', iterator_to_array($data->price($data->secondLab())));
    }

    public function testTheShippedRouterUrlHasNoVersionPathBecauseTheBridgeAppendsIt(): void
    {
        self::assertSame('', new SampleProjectData()->routerUrl()->getPath());
    }

    public function testEachValueIsReadFromTheFileItIsGiven(): void
    {
        $data = $this->dataOf($this->valid());

        self::assertSame(
            ['https://router.example.test', '2030-01-02', 'made-up-strong', 'made-up-second-lab', ['input' => 0.5, 'output' => 2]],
            [$data->routerUrl()->toRawString(), $data->priceDate(), $data->model(Provider::Anthropic, Tier::Strong), $data->secondLab(), iterator_to_array($data->price('made-up-strong'))],
        );
    }

    public function testAModelTheFileDoesNotNameIsRefusedByItsPath(): void
    {
        $this->expectExceptionObject($this->broken('models.openai.light'));

        $this->dataOf($this->valid())->model(Provider::OpenAi, Tier::Light);
    }

    public function testAModelWithoutAPriceIsRefused(): void
    {
        $this->expectExceptionObject($this->broken('prices.made-up-second-lab'));

        $this->dataOf($this->valid())->price('made-up-second-lab');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function brokenPrices(): iterable
    {
        yield 'no amounts' => [[]];
        yield 'an amount in words' => [['input' => 'five']];
        yield 'amounts without kinds' => [[1, 2]];
        yield 'a price that is no list' => ['5'];
    }

    #[DataProvider('brokenPrices')]
    public function testAPriceThatIsNotAmountsByKindIsRefused(mixed $price): void
    {
        $this->expectExceptionObject($this->broken('prices.made-up-strong'));

        $this->dataOf(['prices' => ['made-up-strong' => $price]] + $this->valid())->price('made-up-strong');
    }

    public function testAnEmptyPriceDateIsRefused(): void
    {
        $this->expectExceptionObject($this->broken('price_date'));

        $this->dataOf(['price_date' => ''] + $this->valid())->priceDate();
    }

    public function testARouterUrlThatIsNoUriIsRefused(): void
    {
        $this->expectExceptionObject($this->broken('router_url'));

        $this->dataOf(['router_url' => 'https://router example'] + $this->valid())->routerUrl();
    }

    public function testAFileWhoseTopIsNoMappingNamesNoValue(): void
    {
        new Filesystem()->dumpFile($this->file(), "just text\n");

        $this->expectExceptionObject($this->broken('router_url'));

        new SampleProjectData($this->file())->routerUrl();
    }

    public function testAMissingFileIsNamed(): void
    {
        $failure = null;
        try {
            new SampleProjectData($this->file())->routerUrl();
        } catch (ParseException $failure) {
        }

        self::assertInstanceOf(ParseException::class, $failure);
        self::assertStringContainsString($this->file(), $failure->getMessage());
    }

    public function testAnUnreadableFileIsRefused(): void
    {
        new Filesystem()->dumpFile($this->file(), Yaml::dump($this->valid()));
        chmod($this->file(), 0000);
        if (is_readable($this->file())) {
            self::markTestSkipped('The user running the tests reads files whatever their mode.');
        }

        $this->expectException(ParseException::class);

        new SampleProjectData($this->file())->routerUrl();
    }

    /**
     * @return array<string, mixed>
     */
    private function valid(): array
    {
        return [
            'router_url' => 'https://router.example.test',
            'price_date' => '2030-01-02',
            'models' => ['anthropic' => ['strong' => 'made-up-strong']],
            'second_lab' => 'made-up-second-lab',
            'prices' => ['made-up-strong' => ['input' => 0.5, 'output' => 2]],
        ];
    }

    private function broken(string $path): LogicException
    {
        return new LogicException(sprintf('The sample project data file %s has no valid %s.', $this->file(), $path));
    }

    /**
     * @param array<string, mixed> $contents
     */
    private function dataOf(array $contents): SampleProjectData
    {
        new Filesystem()->dumpFile($this->file(), Yaml::dump($contents));

        return new SampleProjectData($this->file());
    }

    private function file(): string
    {
        return $this->directory.'/sample-project.yaml';
    }
}
