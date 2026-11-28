<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\ModelWaits;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Budget\Contract\LeftOutAccount;
use Intakt\Core\Budget\Contract\RankedAccounts;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Budget\Contract\WindowUse;
use Intakt\Core\Budget\Domain\AccountRoom;
use Intakt\Core\Budget\Domain\CeilingStartGuard;
use Intakt\Core\Budget\Domain\MeasuredRoomRanking;
use Intakt\Tests\Core\Budget\HoldsInMemory;
use Intakt\Tests\Core\Budget\ReadingsInMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(TierPlatform::class)]
#[UsesClass(AccountFailoverPlatform::class)]
#[UsesClass(ModelWaits::class)]
#[UsesClass(MeasuredRoomRanking::class)]
#[UsesClass(CeilingStartGuard::class)]
#[UsesClass(AccountRoom::class)]
#[UsesClass(RankedAccounts::class)]
#[UsesClass(LeftOutAccount::class)]
#[UsesClass(EveryAccountCoolingDown::class)]
#[UsesClass(Account::class)]
#[UsesClass(Provider::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(Tier::class)]
#[UsesClass(Usage::class)]
#[UsesClass(UsageWindow::class)]
#[UsesClass(WindowReading::class)]
#[UsesClass(WindowUse::class)]
final class TierPlatformRankingTest extends TestCase
{
    private MockClock $clock;
    private ReadingsInMemory $readings;

    public function testATierMovesToItsSubstituteWhenEveryAccountOfItsOwnProviderIsAtACeiling(): void
    {
        $this->atWeeklyCeiling(Provider::ClaudeCode);

        self::assertSame(Provider::Codex, $this->tierPlatform(true)->answering('project', Tier::Strong)->provider);
    }

    public function testATierWithRoomOnItsOwnProviderStaysOnIt(): void
    {
        self::assertSame(Provider::ClaudeCode, $this->tierPlatform(true)->answering('project', Tier::Strong)->provider);
    }

    public function testATierWithoutSubstitutesWaitsForTheEarliestResetWhenEveryAccountIsAtACeiling(): void
    {
        $this->atWeeklyCeiling(Provider::ClaudeCode);

        try {
            $this->tierPlatform(false)->answering('project', Tier::Strong);
            self::fail('A model was chosen though every account of its provider is at a ceiling.');
        } catch (EveryAccountCoolingDown $out) {
            self::assertSame(2 * 3600, $out->secondsUntilAnAccountIsFree);
        }
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-03-02 10:00:00 UTC');
        $this->readings = new ReadingsInMemory($this->clock);
    }

    private function atWeeklyCeiling(Provider $provider): void
    {
        $this->readings->record(new WindowReading(new Account($provider, Account::DEFAULT), UsageWindow::Weekly, new Usage(95.0, $this->clock->now()->modify('+2 hours'))));
    }

    private function tierPlatform(bool $withSubstitute): TierPlatform
    {
        $own = new ResolvedModel(Provider::ClaudeCode, 'big');
        $substitute = new ResolvedModel(Provider::Codex, 'big');
        $resolver = $this->createStub(TierResolver::class);
        $resolver->method('hasSubstitutes')->willReturn($withSubstitute);
        $resolver->method('configured')->willReturn($own);
        $resolver->method('resolve')->willReturn($own);
        $resolver->method('substitutes')->willReturn([$substitute]);
        $platforms = new ServiceLocator([
            Provider::ClaudeCode->value => fn (): AccountFailoverPlatform => $this->accounts(Provider::ClaudeCode),
            Provider::Codex->value => fn (): AccountFailoverPlatform => $this->accounts(Provider::Codex),
        ]);

        return new TierPlatform($resolver, $platforms, new NoCooldowns(), new NullLogger());
    }

    private function accounts(Provider $provider): AccountFailoverPlatform
    {
        $ranking = new MeasuredRoomRanking(new HoldsInMemory($this->clock), new CeilingStartGuard($this->readings, $this->clock, ['five-hour' => 90.0, 'weekly' => 90.0]));

        return new AccountFailoverPlatform($provider, [Account::DEFAULT => MockPlatformFactory::createPlatform('answer')], new NoCooldowns(), $this->clock, new NullLogger(), new NoPinnedProviderCooldowns(), $ranking);
    }
}
