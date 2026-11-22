<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server\Infrastructure;

use Intakt\Core\Server\Infrastructure\PathOnlyProfilerStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\HttpKernel\Profiler\Profile;

#[CoversClass(PathOnlyProfilerStorage::class)]
final class PathOnlyProfilerStorageTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-path-only-profiles-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    public function testASavedProfileKeepsThePathOfItsUrlWithoutTheQuery(): void
    {
        $storage = new PathOnlyProfilerStorage(new FileProfilerStorage('file:'.$this->folder));

        $storage->write($this->profile('a1b2c3', 'http://localhost/mcp/review?access_token=canary-query-5e1d'));

        self::assertSame('http://localhost/mcp/review', $storage->read('a1b2c3')?->getUrl());
        self::assertStringNotContainsString('canary-query-5e1d', $this->everythingSaved());
    }

    public function testASavedProfileKeepsAUrlWithoutAQueryAsItIs(): void
    {
        $storage = new PathOnlyProfilerStorage(new FileProfilerStorage('file:'.$this->folder));

        $storage->write($this->profile('d4e5f6', 'http://localhost/reviews/12'));

        self::assertSame('http://localhost/reviews/12', $storage->read('d4e5f6')?->getUrl());
    }

    public function testASavedProfileKeepsNoClientAddress(): void
    {
        $storage = new PathOnlyProfilerStorage(new FileProfilerStorage('file:'.$this->folder));

        $storage->write($this->profile('a7b8c9', 'http://localhost/'));

        self::assertNull($storage->read('a7b8c9')?->getIp());
        self::assertStringNotContainsString('192.0.2.17', $this->everythingSaved());
    }

    public function testASavedProfileKeepsThePathOfItsUrlWithoutTheFragment(): void
    {
        $storage = new PathOnlyProfilerStorage(new FileProfilerStorage('file:'.$this->folder));

        $storage->write($this->profile('b1c2d3', 'http://localhost/reviews/12?x=1#part'));

        self::assertSame('http://localhost/reviews/12', $storage->read('b1c2d3')?->getUrl());
    }

    public function testASavedProfileDropsAQueryHiddenInsideTheFragment(): void
    {
        $storage = new PathOnlyProfilerStorage(new FileProfilerStorage('file:'.$this->folder));

        $storage->write($this->profile('f1a2b3', 'http://localhost/a#f?access_token=canary-frag-3c1d'));

        self::assertSame('http://localhost/a', $storage->read('f1a2b3')?->getUrl());
        self::assertStringNotContainsString('canary-frag-3c1d', $this->everythingSaved());
    }

    public function testASavedProfileKeepsThePathOfAUrlWithAFragmentAndNoQuery(): void
    {
        $storage = new PathOnlyProfilerStorage(new FileProfilerStorage('file:'.$this->folder));

        $storage->write($this->profile('a9b8c7', 'http://localhost/reviews/12#part'));

        self::assertSame('http://localhost/reviews/12', $storage->read('a9b8c7')?->getUrl());
    }

    public function testASavedProfileWithoutAUrlKeepsNoUrl(): void
    {
        $storage = new PathOnlyProfilerStorage(new FileProfilerStorage('file:'.$this->folder));

        $storage->write($this->profile('c1d2e3', null));

        self::assertNull($storage->read('c1d2e3')?->getUrl());
    }

    public function testASavedProfileWithAnUnreadableUrlKeepsNoUrl(): void
    {
        $storage = new PathOnlyProfilerStorage(new FileProfilerStorage('file:'.$this->folder));

        $storage->write($this->profile('e1f2a3', 'http://[bad/x?access_token=canary-bad-7f2a'));

        self::assertNull($storage->read('e1f2a3')?->getUrl());
        self::assertStringNotContainsString('canary-bad-7f2a', $this->everythingSaved());
    }

    private function profile(string $token, ?string $url): Profile
    {
        $profile = new Profile($token);
        $profile->setUrl($url);
        $profile->setIp('192.0.2.17');
        $profile->setMethod('POST');
        $profile->setStatusCode(401);
        // Now, because the storage removes profiles older than two days on a random one of its writes.
        $profile->setTime(new NativeClock()->now()->getTimestamp());

        return $profile;
    }

    // The index beside the profiles holds the URL and address too, so every written file is read; the index and its offset file are plain text.
    private function everythingSaved(): string
    {
        $saved = '';
        foreach (new Finder()->files()->in($this->folder) as $file) {
            $content = $file->getContents();
            $saved .= str_starts_with($file->getFilename(), 'index.csv') ? $content : (string) gzdecode($content);
        }

        return $saved;
    }
}
