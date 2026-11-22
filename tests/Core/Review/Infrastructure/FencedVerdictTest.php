<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\ResolvedFinding;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Infrastructure\AnswerForm;
use Intakt\Core\Review\Infrastructure\FenceLine;
use Intakt\Core\Review\Infrastructure\ReviewerAnswer\Verdict as ReviewerAnswer;
use Intakt\Core\Review\Infrastructure\StrictVerdictConverter;
use Intakt\Core\Review\Infrastructure\StrictVerdictSubscriber;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\ClaudeCode\ClaudeCode;
use Symfony\AI\Platform\Bridge\Codex\Codex;
use Symfony\AI\Platform\Event\ResultEvent;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(FenceLine::class)]
#[CoversClass(StrictVerdictConverter::class)]
#[CoversClass(StrictVerdictSubscriber::class)]
#[UsesClass(AnswerForm::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(ReviewerAnswer::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Evidence::class)]
final class FencedVerdictTest extends TestCase
{
    private const string VERDICT = '{"findings":[],"verdict":"changes","resolved":["F1"],"follow_ups":[]}';

    #[DataProvider('subscriptionModels')]
    public function testProseAroundOneFencedJsonBlockIsReadAsThatBlock(Model $model): void
    {
        $verdict = $this->answered($model, "I read the change and found it fine.\n\n```json\n".self::VERDICT."\n```\n\nThat is my verdict.");

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertEquals([new ResolvedFinding('F1')], $verdict->resolved);
    }

    #[DataProvider('subscriptionModels')]
    public function testBareJsonStillWorks(Model $model): void
    {
        self::assertSame(Decision::Changes, $this->answered($model, self::VERDICT)->verdict);
    }

    #[DataProvider('subscriptionModels')]
    public function testAnAnswerWithNoFencedBlockIsInvalid(Model $model): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, 'I found the change fine, with no verdict object.');
    }

    #[DataProvider('subscriptionModels')]
    public function testAnAnswerWithTwoFencedBlocksIsInvalid(Model $model): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "First:\n```json\n".self::VERDICT."\n```\nSecond:\n```json\n".self::VERDICT."\n```");
    }

    #[DataProvider('subscriptionModels')]
    public function testOneFencedBlockWithWindowsLineEndingsIsReadAsThatBlock(Model $model): void
    {
        $verdict = $this->answered($model, "Review.\r\n```json\r\n".self::VERDICT."\r\n```\r\n");

        self::assertSame(Decision::Changes, $verdict->verdict);
    }

    #[DataProvider('subscriptionModels')]
    public function testTwoFencedBlocksWithMixedLineEndingsAreInvalid(Model $model): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "First:\n```json\n".self::VERDICT."\n```\nSecond:\r\n```json\r\n".self::VERDICT."\r\n```\r\n");
    }

    #[DataProvider('subscriptionModels')]
    public function testAClosedBlockBesideAnUnclosedOneIsInvalid(Model $model): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "First:\n```json\n".self::VERDICT."\n```\nSecond:\n```json\n".self::VERDICT);
    }

    #[DataProvider('subscriptionModelsWithFenceIndents')]
    public function testAnIndentedBlockBesideAPlainOneIsInvalid(Model $model, string $indent): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "Draft:\n{$indent}```json\n{$indent}".self::VERDICT."\n{$indent}```\nFinal:\n```json\n".self::VERDICT."\n```");
    }

    #[DataProvider('subscriptionModels')]
    public function testAFourBacktickBlockBesideAPlainOneIsInvalid(Model $model): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "Draft:\n````json\n".self::VERDICT."\n````\nFinal:\n```json\n".self::VERDICT."\n```");
    }

    #[DataProvider('subscriptionModels')]
    public function testATildeBlockBesideAPlainOneIsInvalid(Model $model): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "Draft:\n~~~json\n".self::VERDICT."\n~~~\nFinal:\n```json\n".self::VERDICT."\n```");
    }

    #[DataProvider('subscriptionModels')]
    public function testAnUppercaseJsonBlockBesideAPlainOneIsInvalid(Model $model): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "Draft:\n```JSON\n".self::VERDICT."\n```\nFinal:\n```json\n".self::VERDICT."\n```");
    }

    #[DataProvider('subscriptionModels')]
    public function testABlockquotedBlockBesideAPlainOneIsInvalid(Model $model): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "Draft:\n> ```json\n> ".self::VERDICT."\n> ```\n\nFinal:\n```json\n".self::VERDICT."\n```");
    }

    #[DataProvider('subscriptionModels')]
    public function testAListItemBlockBesideAPlainOneIsInvalid(Model $model): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "Drafts:\n- ```json\n  ".self::VERDICT."\n  ```\n\nFinal:\n```json\n".self::VERDICT."\n```");
    }

    #[DataProvider('subscriptionModels')]
    public function testABlockNestedInALongerTextFenceIsInvalid(Model $model): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "Example:\n````text\n```json\n".self::VERDICT."\n```\n````\n");
    }

    #[DataProvider('subscriptionModels')]
    public function testABlockAfterAnOpenFenceWithAnEvenCountOfFenceLinesIsInvalid(Model $model): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "Example:\n```text\n```python\n```json\n".self::VERDICT."\n```\n```\n");
    }

    #[DataProvider('subscriptionModels')]
    public function testABlockAfterAnOddCountOfFenceLinesIsInvalid(Model $model): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "Example:\n```text\n```python\n```\n```json\n".self::VERDICT."\n```\n");
    }

    #[DataProvider('subscriptionModelsWithPrefixedFenceLines')]
    public function testABlockAfterAPrefixedFenceLineInsideATextFenceIsInvalid(Model $model, string $prefix): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "Example:\n```text\n{$prefix}```\n```json\n".self::VERDICT."\n```\n");
    }

    #[DataProvider('subscriptionModels')]
    public function testABlockAfterABareFenceLineFollowingAQuotedOpeningIsInvalid(Model $model): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "Example:\n> ```text\n```\n```json\n".self::VERDICT."\n```\n");
    }

    #[DataProvider('subscriptionModels')]
    public function testAQuotedBlockClosedByAQuotedFenceBesideThePlainOneIsRead(Model $model): void
    {
        $verdict = $this->answered($model, "Quoted:\n> ```text\n> example\n> ```\n\nVerdict:\n```json\n".self::VERDICT."\n```\n");

        self::assertSame(Decision::Changes, $verdict->verdict);
    }

    #[DataProvider('subscriptionModels')]
    public function testATextBlockClosedByAFenceIndentedThreeSpacesBesideThePlainOneIsRead(Model $model): void
    {
        $verdict = $this->answered($model, "Example:\n```text\nexample\n   ```\nVerdict:\n```json\n".self::VERDICT."\n```\n");

        self::assertSame(Decision::Changes, $verdict->verdict);
    }

    #[DataProvider('subscriptionModels')]
    public function testAnInlineJsonFenceMentionBesideAPlainBlockIsInvalid(Model $model): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "I answer in a ```json block as asked.\n```json\n".self::VERDICT."\n```");
    }

    #[DataProvider('subscriptionModels')]
    public function testAClosedPhpBlockBesideThePlainOneIsRead(Model $model): void
    {
        $verdict = $this->answered($model, "The change adds:\n```php\necho 1;\n```\nVerdict:\n```json\n".self::VERDICT."\n```\n");

        self::assertSame(Decision::Changes, $verdict->verdict);
    }

    #[DataProvider('subscriptionModels')]
    public function testALoneFourBacktickBlockIsNotRead(Model $model): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "Here:\n````json\n".self::VERDICT."\n````");
    }

    #[DataProvider('subscriptionModels')]
    public function testALoneIndentedBlockIsNotRead(Model $model): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "Here:\n ```json\n ".self::VERDICT."\n ```");
    }

    #[DataProvider('subscriptionModels')]
    public function testAFencedBlockThatIsNotAVerdictIsInvalid(Model $model): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "Here:\n```json\n{\"note\":\"fine\"}\n```");
    }

    #[DataProvider('subscriptionModels')]
    public function testABlockFencedWithAnotherLanguageIsNotTheVerdict(Model $model): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "Here:\n```php\n".self::VERDICT."\n```");
    }

    public function testAProviderWithNativeStructuredOutputKeepsTheStrictReading(): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered(new Model('claude-opus-5-5'), "Prose.\n```json\n".self::VERDICT."\n```");
    }

    /**
     * @return iterable<string, array{Model}>
     */
    public static function subscriptionModels(): iterable
    {
        yield 'claude_code' => [new ClaudeCode('sonnet')];
        yield 'codex' => [new Codex('gpt-5')];
    }

    /**
     * @return iterable<string, array{Model, string}>
     */
    public static function subscriptionModelsWithFenceIndents(): iterable
    {
        // Three spaces is the most Markdown still reads as a fence, so it is the boundary of what must be counted.
        foreach (['one space' => ' ', 'three spaces' => '   '] as $name => $indent) {
            foreach (self::subscriptionModels() as $provider => [$model]) {
                yield sprintf('%s, %s', $provider, $name) => [$model, $indent];
            }
        }
    }

    /**
     * @return iterable<string, array{Model, string}>
     */
    public static function subscriptionModelsWithPrefixedFenceLines(): iterable
    {
        // Inside an open block such a line is content in Markdown, so it must not count as the block's close.
        foreach (['quoted' => '> ', 'four spaces' => '    ', 'list item' => '- ', 'tab' => "\t"] as $name => $prefix) {
            foreach (self::subscriptionModels() as $provider => [$model]) {
                yield sprintf('%s, %s', $provider, $name) => [$model, $prefix];
            }
        }
    }

    private function answered(Model $model, string $text): Verdict
    {
        $formats = new ResponseFormatFactory();
        $options = [PlatformSubscriber::RESPONSE_FORMAT => $formats->create(ReviewerAnswer::class)];
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('convert')->willReturn(new TextResult($text));
        $deferred = new DeferredResult($converter, new InMemoryRawResult(), $options);
        $event = new ResultEvent($model, $deferred, $options);

        new StrictVerdictSubscriber($formats, StoredSerializerFactory::create(), self::createStub(ValidatorInterface::class))->processResult($event);

        $result = $event->getDeferredResult()->getResult();
        self::assertInstanceOf(ObjectResult::class, $result);
        $verdict = $result->getContent();
        self::assertInstanceOf(ReviewerAnswer::class, $verdict);

        return $verdict->toVerdict();
    }
}
