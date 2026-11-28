<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Infrastructure\LiveBoardHeadline;
use Intakt\Core\Dashboard\Infrastructure\LiveProjectCards;
use Intakt\Core\Dashboard\Infrastructure\PublishFirstReview;
use Intakt\Core\Dashboard\Infrastructure\PublishProjectFilter;
use Intakt\Core\Dashboard\Infrastructure\RoundStream;
use Intakt\Core\Dashboard\Infrastructure\TourCaptionUpdates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoundStream::class)]
final class RoundStreamTest extends TestCase
{
    public function testEveryStreamHasItsTemplateFile(): void
    {
        foreach (RoundStream::cases() as $stream) {
            self::assertFileExists(dirname(__DIR__, 4).'/templates/'.$stream->template(), $stream->name);
        }
    }

    public function testEveryTemplateFileBelongsToARoundStreamOrAShellPublisher(): void
    {
        $streams = [...array_map(static fn (RoundStream $stream): string => $stream->template(), RoundStream::cases()), PublishProjectFilter::TEMPLATE, PublishFirstReview::TEMPLATE, LiveBoardHeadline::TEMPLATE, TourCaptionUpdates::TEMPLATE, TourCaptionUpdates::REFUSAL_TEMPLATE, LiveProjectCards::TEMPLATE];
        $files = glob(dirname(__DIR__, 4).'/templates/dashboard/stream/[!_]*.stream.html.twig');
        self::assertIsArray($files);

        foreach ($files as $file) {
            self::assertContains('dashboard/stream/'.basename($file), $streams);
        }
    }
}
