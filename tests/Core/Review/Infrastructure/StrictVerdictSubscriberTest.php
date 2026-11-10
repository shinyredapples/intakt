<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Infrastructure\StrictVerdictSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Event\ResultEvent;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(StrictVerdictSubscriber::class)]
#[UsesClass(Evidence::class)]
final class StrictVerdictSubscriberTest extends TestCase
{
    public function testAnotherResponseFormatIsLeftToSymfonyAi(): void
    {
        $deferred = new DeferredResult(self::createStub(ResultConverterInterface::class), new InMemoryRawResult());
        $options = [PlatformSubscriber::RESPONSE_FORMAT => new ResponseFormatFactory()->create(Evidence::class)];
        $event = new ResultEvent(new Model('reviewer'), $deferred, $options, 'Review the change.');

        new StrictVerdictSubscriber(new ResponseFormatFactory(), self::createStub(SerializerInterface::class), self::createStub(ValidatorInterface::class))->processResult($event);

        self::assertSame($deferred, $event->getDeferredResult());
    }
}
