<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use Intakt\Tests\Application\KernelTest;
use Intakt\Tests\Support\Log\Fixtures\KernelTestCaseWithoutTheTraitFixture;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

#[CoversNothing]
final class KernelTestCasesFailOnUnassertedErrorsTest extends TestCase
{
    /**
     * @param class-string<KernelTestCase> $class
     */
    #[DataProvider('kernelTestCases')]
    public function testEveryKernelTestCaseFailsOnUnassertedErrorRecords(string $class): void
    {
        self::assertTrue(self::failsOnUnassertedErrors($class), sprintf('%s does not extend Intakt\Tests\Support\KernelTestCase or WebTestCase.', $class));
    }

    public function testTheApplicationKernelTestFailsOnUnassertedErrorRecords(): void
    {
        self::assertTrue(self::failsOnUnassertedErrors(KernelTest::class));
    }

    public function testAKernelTestCaseWithoutTheTraitIsFound(): void
    {
        self::assertFalse(self::failsOnUnassertedErrors(KernelTestCaseWithoutTheTraitFixture::class));
    }

    /**
     * @return iterable<string, array{class-string<KernelTestCase>}>
     */
    public static function kernelTestCases(): iterable
    {
        $tests = new Finder()->files()->in(dirname(__DIR__, 2))->name('*Test.php')->exclude(['Gates', 'Fixtures']);
        foreach ($tests as $test) {
            $class = 'Intakt\Tests\\'.str_replace('/', '\\', substr($test->getRelativePathname(), 0, -4));
            if (is_subclass_of($class, KernelTestCase::class) && !new ReflectionClass($class)->isAbstract()) {
                yield $class => [$class];
            }
        }
    }

    /**
     * @param class-string $class
     */
    private static function failsOnUnassertedErrors(string $class): bool
    {
        $hook = new ReflectionMethod($class, 'assertPostConditions')->getDeclaringClass();

        return in_array(AssertsLoggedErrors::class, $hook->getTraitNames(), true);
    }
}
