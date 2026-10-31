<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Infrastructure\ProjectsConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(ProjectsConfiguration::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
#[UsesClass(Account::class)]
final class ProjectsConfigurationTest extends TestCase
{
    public function testProjectsKeepTheirTierMappings(): void
    {
        $config = $this->process(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'openai', 'model' => 'mini']]]]]);

        self::assertSame(['alpha' => ['tiers' => ['light' => ['provider' => 'openai', 'model' => 'mini']]]], $config['projects']);
    }

    public function testNoProjectsIsValid(): void
    {
        self::assertSame([], $this->process([])['projects']);
    }

    public function testEveryProviderHasItsDefaultAccountWhenNoneIsListed(): void
    {
        $accounts = $this->process([])['accounts'];

        self::assertSame(array_fill_keys(array_column(Provider::cases(), 'value'), [Account::DEFAULT]), $accounts);
    }

    public function testListedAccountsKeepTheOwnersOrder(): void
    {
        $accounts = (array) $this->process(['accounts' => ['anthropic' => ['team', 'default']]])['accounts'];

        self::assertSame(['team', 'default'], (array) $accounts['anthropic']);
        self::assertSame([Account::DEFAULT], (array) $accounts['openai']);
    }

    /**
     * @return iterable<string, array{list<mixed>}>
     */
    public static function refusedAccountLists(): iterable
    {
        yield 'no account' => [[]];
        yield 'an uppercase name' => [['Team']];
        yield 'a name with a dot' => [['team.b']];
        yield 'a name starting with a digit' => [['2nd']];
        yield 'an account listed twice' => [['team', 'team']];
    }

    /**
     * @param list<mixed> $names
     */
    #[DataProvider('refusedAccountLists')]
    public function testAnAccountListThatCannotNameAnAccountIsRefused(array $names): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['accounts' => ['anthropic' => $names]]);
    }

    public function testAnAccountOfAnUnknownProviderIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['accounts' => ['nowhere' => ['default']]]);
    }

    public function testAnUnknownProviderIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'nowhere', 'model' => 'm']]]]]);
    }

    public function testAnUnknownTierIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['projects' => ['alpha' => ['tiers' => ['huge' => ['provider' => 'openai', 'model' => 'm']]]]]);
    }

    public function testAMappingWithoutAModelIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'openai']]]]]);
    }

    public function testAProjectNameKeepsItsDashes(): void
    {
        $config = $this->process(['projects' => ['my-project' => ['tiers' => []]]]);

        self::assertSame(['my-project'], array_keys((array) $config['projects']));
    }

    public function testAModelThatIsNoStringIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'openai', 'model' => 5]]]]]);
    }

    #[DataProvider('blankModels')]
    public function testAModelMadeOnlyOfWhitespaceIsRefusedNamingTheKey(string $model): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/intakt\.projects\.alpha\.tiers\.light\.model.*The "model" key must not be blank\./');

        $this->process(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'openai', 'model' => $model]]]]]);
    }

    public function testAModelThatIsNotValidUtf8IsRefusedNamingTheKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/intakt\.projects\.alpha\.tiers\.light\.model.*must be valid UTF-8/');

        $this->process(['projects' => ['alpha' => ['tiers' => ['light' => ['provider' => 'openai', 'model' => "\xA0"]]]]]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankModels(): iterable
    {
        yield 'ASCII spaces' => ['   '];
        yield 'no-break space' => ["\u{00A0}"];
    }

    /**
     * @param array<string, mixed> $contents
     *
     * @return array<string, mixed>
     */
    private function process(array $contents): array
    {
        /** @var array<string, mixed> $processed */
        $processed = new Processor()->processConfiguration(new ProjectsConfiguration(), [$contents]);

        return $processed;
    }
}
