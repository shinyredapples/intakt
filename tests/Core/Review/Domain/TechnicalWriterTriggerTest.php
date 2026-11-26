<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\DocsPages;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\PathGlob;
use Intakt\Core\Review\Domain\TechnicalWriterTrigger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TechnicalWriterTrigger::class)]
#[UsesClass(DocsPages::class)]
#[UsesClass(ChangeDescription::class)]
#[UsesClass(Paths::class)]
#[UsesClass(Labels::class)]
#[UsesClass(PathGlob::class)]
final class TechnicalWriterTriggerTest extends TestCase
{
    private const array PATHS = ['docs/user/**', 'docs/guide/**', 'README.md'];

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function pagesPeopleRead(): iterable
    {
        yield 'a user chapter' => [['src/Cart.php', 'docs/user/03-reviews.md']];
        yield 'a plain-words guide' => [['docs/guide/glossary.md']];
        yield 'the README' => [['README.md']];
        yield 'a nested user page' => [['docs/user/deep/page.md']];
    }

    /**
     * @param list<string> $paths
     */
    #[DataProvider('pagesPeopleRead')]
    public function testAChangeToAPagePeopleReadCallsTheTechnicalWriter(array $paths): void
    {
        self::assertTrue($this->trigger()->matches($this->change($paths)));
    }

    public function testAChangeToCodeOnlyDoesNotCallTheTechnicalWriter(): void
    {
        self::assertFalse($this->trigger()->matches($this->change(['src/Cart.php', 'tests/CartTest.php'])));
    }

    public function testAChangeToAReferencePageAloneDoesNotCallTheTechnicalWriter(): void
    {
        self::assertFalse($this->trigger()->matches($this->change(['docs/reference/user-manual.md'])));
    }

    public function testTheLabelWidensThePathsToAnyMarkdownPageUnderDocs(): void
    {
        self::assertTrue($this->trigger()->matches($this->change(['docs/reference/user-manual.md'], ChangeDescription::TECHNICAL_WRITER_LABEL)));
    }

    public function testTheLabelDoesNotCallTheTechnicalWriterForAChangeWithoutAPage(): void
    {
        self::assertFalse($this->trigger()->matches($this->change(['src/Cart.php', 'CONTRIBUTING.md'], ChangeDescription::TECHNICAL_WRITER_LABEL)));
    }

    public function testATriggerPathWithoutAMarkdownPageDoesNotCallTheTechnicalWriter(): void
    {
        self::assertFalse($this->trigger()->matches($this->change(['docs/user/screenshot.png'])));
    }

    public function testAREADMEBelowTheRootIsNoPageOutsideDocs(): void
    {
        self::assertFalse($this->trigger()->matches($this->change(['src/README.md'])));
    }

    public function testATouchedSummaryOfATechnicalPageCallsTheTechnicalWriter(): void
    {
        $change = new ChangeDescription(3, new Paths('docs/architecture/ledger.md'), new Labels(), new Paths('docs/architecture/ledger.md'));

        self::assertTrue($this->trigger()->matches($change));
    }

    public function testATechnicalPageWhoseSummaryIsUntouchedDoesNotCallTheTechnicalWriter(): void
    {
        $change = new ChangeDescription(3, new Paths('docs/architecture/ledger.md'), new Labels(), new Paths());

        self::assertFalse($this->trigger()->matches($change));
    }

    public function testTheFullReviewLabelDoesNotCallTheTechnicalWriterForCode(): void
    {
        self::assertFalse($this->trigger()->matches($this->change(['src/Cart.php'], ChangeDescription::FULL_REVIEW_LABEL)));
    }

    public function testTheProjectPathsDecideWhichPagesCount(): void
    {
        $trigger = new TechnicalWriterTrigger(['docs/handbook/**']);

        self::assertTrue($trigger->matches($this->change(['docs/handbook/start.md'])));
        self::assertFalse($trigger->matches($this->change(['docs/user/start.md'])));
    }

    private function trigger(): TechnicalWriterTrigger
    {
        return new TechnicalWriterTrigger(self::PATHS);
    }

    /**
     * @param list<string> $paths
     */
    private function change(array $paths, string ...$labels): ChangeDescription
    {
        return new ChangeDescription(1, new Paths(...$paths), new Labels(...$labels));
    }
}
