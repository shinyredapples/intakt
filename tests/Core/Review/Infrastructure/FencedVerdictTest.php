<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Infrastructure\AnswerForm;
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

#[CoversClass(StrictVerdictConverter::class)]
#[CoversClass(StrictVerdictSubscriber::class)]
#[UsesClass(AnswerForm::class)]
#[UsesClass(Verdict::class)]
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
        self::assertSame(['F1'], $verdict->resolved);
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

    #[DataProvider('subscriptionModels')]
    public function testAnIndentedBlockBesideAPlainOneIsInvalid(Model $model): void
    {
        $this->expectException(RuntimeException::class);

        $this->answered($model, "Draft:\n   ```json\n   ".self::VERDICT."\n   ```\nFinal:\n```json\n".self::VERDICT."\n```");
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

    private function answered(Model $model, string $text): Verdict
    {
        $formats = new ResponseFormatFactory();
        $options = [PlatformSubscriber::RESPONSE_FORMAT => $formats->create(Verdict::class)];
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('convert')->willReturn(new TextResult($text));
        $deferred = new DeferredResult($converter, new InMemoryRawResult(), $options);
        $event = new ResultEvent($model, $deferred, $options);

        new StrictVerdictSubscriber($formats, StoredSerializerFactory::create(), self::createStub(ValidatorInterface::class))->processResult($event);

        $result = $event->getDeferredResult()->getResult();
        self::assertInstanceOf(ObjectResult::class, $result);
        $verdict = $result->getContent();
        self::assertInstanceOf(Verdict::class, $verdict);

        return $verdict;
    }
}
