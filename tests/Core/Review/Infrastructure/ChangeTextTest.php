<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Infrastructure\ChangeText;
use Intakt\Core\Review\Infrastructure\DiffPaths;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangeText::class)]
#[UsesClass(DiffPaths::class)]
#[UsesClass(Checkout::class)]
final class ChangeTextTest extends TestCase
{
    private TemporaryRepository $repository;

    public function testTheChangeHoldsTheDiffAndTheFilesItTouches(): void
    {
        $diff = $this->diffOf('src/orders.js');

        self::assertSame($diff."\nfunction orderLog(customerName) {}\n", new ChangeText(new DiffPaths())->of($diff, $this->repository->path));
    }

    public function testAFileNoLongerInTheCheckoutIsOnlyInTheDiff(): void
    {
        $diff = $this->diffOf('src/deleted.js');

        self::assertSame($diff, new ChangeText(new DiffPaths())->of($diff, $this->repository->path));
    }

    public function testAPathOutsideTheCheckoutIsNeverRead(): void
    {
        $diff = $this->diffOf('../outside.js');
        file_put_contents(dirname($this->repository->path).'/outside.js', 'secretName');

        try {
            self::assertSame($diff, new ChangeText(new DiffPaths())->of($diff, $this->repository->path));
        } finally {
            unlink(dirname($this->repository->path).'/outside.js');
        }
    }

    public function testARemovedCheckoutLeavesTheDiffAlone(): void
    {
        $diff = $this->diffOf('src/orders.js');

        self::assertSame($diff, new ChangeText(new DiffPaths())->of($diff, $this->repository->path.'/gone'));
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('src/orders.js', "function orderLog(customerName) {}\n");
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    private function diffOf(string $path): string
    {
        return sprintf("diff --git a/%s b/%s\n+changed\n", $path, $path);
    }
}
