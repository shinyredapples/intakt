<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Infrastructure\StrictVerdictConverter;
use Intakt\Kernel;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;

#[CoversClass(StrictVerdictConverter::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Evidence::class)]
#[UsesClass(Kernel::class)]
final class StrictVerdictConverterTest extends KernelTestCase
{
    public function testAToolCallPassesOnAsItIs(): void
    {
        $toolCall = new ToolCallResult([new ToolCall('call-1', 'read_file')]);

        self::assertSame($toolCall, $this->converting($toolCall));
    }

    public function testAVerdictWithEveryFieldMapsToTheVerdictObject(): void
    {
        $result = $this->converting(new TextResult(VerdictDocument::fromTheDesign()->with('verdict', 'approve')->json()));

        self::assertInstanceOf(ObjectResult::class, $result);
        self::assertInstanceOf(Verdict::class, $result->getContent());
        self::assertSame(Decision::Approve, $result->getContent()->verdict);
    }

    public function testAnExtraFieldIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->converting(new TextResult(VerdictDocument::fromTheDesign()->with('confidence', 'high')->json()));
    }

    public function testAMissingFieldIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->converting(new TextResult(VerdictDocument::fromTheDesign()->without('follow_ups')->json()));
    }

    public function testAnObjectWhereTheFindingsAreAListIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->converting(new TextResult(VerdictDocument::fromTheDesign()->with('findings', ['first' => VerdictDocument::fromTheDesign()->finding()])->json()));
    }

    public function testAnAnswerThatIsNotTextIsRefused(): void
    {
        $this->expectException(UnexpectedResultTypeException::class);

        $this->converting(new MultiPartResult([new TextResult(VerdictDocument::fromTheDesign()->json())]));
    }

    private function converting(ResultInterface $answer): ResultInterface
    {
        $inner = self::createStub(ResultConverterInterface::class);
        $inner->method('convert')->willReturn($answer);
        /** @var SerializerInterface $serializer */
        $serializer = self::getContainer()->get(SerializerInterface::class);

        return new StrictVerdictConverter($inner, $serializer)->convert(new InMemoryRawResult());
    }
}
