<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\NoServiceLocatorRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<NoServiceLocatorRule>
 */
#[CoversClass(NoServiceLocatorRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class NoServiceLocatorRuleTest extends PhpStanRuleTestCase
{
    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRun([
            'AFinalReadonlyServiceTakingAPsrContainerFailsStaticAnalysis' => ['psr-container-service', 'src/Dispatcher.php:13 intakt.noServiceLocator Constructor of Intakt\Sample\Dispatcher takes $handlers as a service locator (Psr\Container\ContainerInterface): no service locator in src/'],
            'AFinalReadonlyServiceTakingSymfonysContainerAsAPromotedParameterFailsStaticAnalysis' => ['symfony-container-promoted', 'src/Mailer.php:12 intakt.noServiceLocator Constructor of Intakt\Sample\Mailer takes $container as a service locator (Symfony\Component\DependencyInjection\ContainerInterface)'],
            'AnAutowireLocatorAttributeFailsStaticAnalysis' => ['autowire-locator', 'src/Handlers.php:16 intakt.noServiceLocator Attribute #[AutowireLocator] injects a service locator'],
            'AFinalReadonlyServiceWithTypedDependenciesAndAContainerConstantPassesStaticAnalysis' => ['typed-service-dependencies'],
        ], 'src/');
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }

    public function testAFinalReadonlyServiceTakingAPsrContainerFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('psr-container-service', 'src/Dispatcher.php:13 intakt.noServiceLocator Constructor of Intakt\Sample\Dispatcher takes $handlers as a service locator (Psr\Container\ContainerInterface)');
    }

    public function testAFinalReadonlyServiceTakingSymfonysContainerAsAPromotedParameterFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('symfony-container-promoted', 'src/Mailer.php:12 intakt.noServiceLocator Constructor of Intakt\Sample\Mailer takes $container as a service locator (Symfony\Component\DependencyInjection\ContainerInterface)');
    }

    public function testANullableServiceLocatorParameterFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('nullable-service-locator', 'src/Router.php:15 intakt.noServiceLocator Constructor of Intakt\Sample\Router takes $routes as a service locator (Symfony\Component\DependencyInjection\ServiceLocator)');
    }

    public function testAContainerInAUnionWrittenInLowerCaseFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('container-in-a-union', 'src/Loader.php:12 intakt.noServiceLocator Constructor of Intakt\Sample\Loader takes $source as a service locator (Psr\Container\ContainerInterface)');
    }

    public function testAServiceLocatorInAnIntersectionFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('container-in-an-intersection', 'src/Resolver.php:13 intakt.noServiceLocator Constructor of Intakt\Sample\Resolver takes $handlers as a service locator (Symfony\Component\DependencyInjection\ServiceLocator)');
    }

    public function testAContainerInTheConstructorOfAnAnonymousClassFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('container-in-an-anonymous-class', 'src/Probes.php:14 intakt.noServiceLocator Constructor of class@anonymous takes $container as a service locator (Psr\Container\ContainerInterface)');
    }

    public function testAContainerInTheConstructorOfATraitFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('container-in-a-trait-constructor', 'src/BootsServices.php:12 intakt.noServiceLocator Constructor of Intakt\Sample\BootsServices takes $container as a service locator (Symfony\Component\DependencyInjection\ContainerInterface)');
    }

    public function testATraitMethodTakingAContainerAliasedAsTheConstructorFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('container-in-an-aliased-trait-constructor', 'src/Gateway.php:9 intakt.noServiceLocator Constructor of Intakt\Sample\Gateway takes $container as a service locator (Psr\Container\ContainerInterface)');
    }

    public function testATraitMethodTakingAContainerAliasedAsAnotherMethodPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('plainly-aliased-trait-method');
    }

    public function testAnAutowireLocatorAttributeFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('autowire-locator', 'src/Handlers.php:16 intakt.noServiceLocator Attribute #[AutowireLocator] injects a service locator');
    }

    public function testTheFindingSaysHowToComply(): void
    {
        $this->assertRuleRejects('psr-container-service', 'inject each service the class needs through its constructor, or a tagged set as an iterable with #[AutowireIterator].');
    }

    public function testAFinalReadonlyServiceWithTypedDependenciesAndAContainerConstantPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('typed-service-dependencies');
    }

    public function testATestHelperTakingTheContainerPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('container-in-a-test-helper');
    }

    protected function ruleFor(string $root): NoServiceLocatorRule
    {
        return new NoServiceLocatorRule($root, self::createReflectionProvider());
    }
}
