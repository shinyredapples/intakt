<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\Ai\Infrastructure\FinishedProcessResult;
use Intakt\Core\Review\Contract\ConversationAlreadyStarted;
use Intakt\Core\Review\Infrastructure\FencedToolbox;
use Intakt\Core\Review\Infrastructure\HoldRoundUntilEveryReviewerFinished;
use Intakt\Core\Review\Infrastructure\MalformedToolCalls;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\StrictVerdictConverter;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

// The gate tests are brought in line by their own task, so this check reads the test classes outside tests/Gates/.
#[CoversNothing]
final class CoverageOwnershipTest extends TestCase
{
    private const string TESTS = __DIR__.'/..';

    public function testEveryTestClassDeclaresWhatItCovers(): void
    {
        $silent = array_keys(array_filter($this->coversByTestClass(), fn (array $covers, string $test): bool => [] === $covers && !$this->coversNothing($test), ARRAY_FILTER_USE_BOTH));

        self::assertSame([], $silent);
    }

    public function testEveryCoveredClassIsCoveredByExactlyOneTestClass(): void
    {
        $owners = [];
        foreach ($this->coversByTestClass() as $test => $covers) {
            foreach ($covers as $covered) {
                $owners[$covered][] = $test;
            }
        }

        self::assertSame([], array_filter($owners, static fn (array $tests): bool => count($tests) > 1));
    }

    public function testATestClassOutsideAUseCaseCoversOneClassAndIsNamedAfterIt(): void
    {
        $misnamed = [];
        foreach ($this->coversByTestClass() as $test => $covers) {
            if ([] === $covers || str_contains($test, '\\UseCase\\')) {
                continue;
            }
            if (1 !== count($covers) || new ReflectionClass($covers[0])->getShortName().'Test' !== new ReflectionClass($test)->getShortName()) {
                $misnamed[] = $test;
            }
        }

        self::assertSame([], $misnamed);
    }

    public function testTheClassesThatOnceLostTheirOnlyCoveringTestClassHaveOne(): void
    {
        $covered = array_merge(...array_values($this->coversByTestClass()));

        foreach ([ReviewerPrompt::class, FencedToolbox::class, StrictVerdictConverter::class, MalformedToolCalls::class, ConversationAlreadyStarted::class, HoldRoundUntilEveryReviewerFinished::class, UsageLedger::class, FinishedProcessResult::class] as $class) {
            self::assertContains($class, $covered);
        }
    }

    /**
     * @return array<class-string, list<class-string>>
     */
    private function coversByTestClass(): array
    {
        $covers = [];
        foreach (new Finder()->files()->in(self::TESTS)->exclude('Gates')->name('*Test.php') as $file) {
            $test = 'Intakt\\Tests\\'.str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
            self::assertTrue(class_exists($test), $file->getRelativePathname().' does not declare the test class its path names.');
            $covers[$test] = array_map(static fn ($attribute): string => $attribute->newInstance()->className(), new ReflectionClass($test)->getAttributes(CoversClass::class));
        }

        return $covers;
    }

    /**
     * @param class-string $test
     */
    private function coversNothing(string $test): bool
    {
        return [] !== new ReflectionClass($test)->getAttributes(CoversNothing::class);
    }
}
