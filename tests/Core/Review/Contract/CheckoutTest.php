<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\CheckoutNotADirectory;
use Intakt\Core\Review\Contract\PathNotInCheckout;
use Intakt\Core\Review\Contract\PathOutsideCheckout;
use Intakt\Tests\Core\Review\Infrastructure\Repository\ReviewCheckout;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Checkout::class)]
#[UsesClass(PathOutsideCheckout::class)]
#[UsesClass(PathNotInCheckout::class)]
#[UsesClass(CheckoutNotADirectory::class)]
final class CheckoutTest extends TestCase
{
    private ReviewCheckout $fixture;

    public function testAFileInsideTheCheckoutResolvesToItsRealPath(): void
    {
        $real = $this->fixture->checkout->resolve('src/Cart.php');

        self::assertSame('src/Cart.php', $this->fixture->checkout->relative($real));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function escapingPaths(): iterable
    {
        yield 'parent segment' => ['../outside/secret.txt'];
        yield 'parent inside a path' => ['src/../../outside/secret.txt'];
        yield 'absolute path' => ['/etc/passwd'];
        yield 'windows drive' => ['C:\\Windows'];
        yield 'null byte' => ["README.md\0.png"];
        yield 'symlink out' => ['escape/secret.txt'];
        yield 'git directory' => ['.git/config'];
    }

    #[DataProvider('escapingPaths')]
    public function testAPathThatEscapesTheCheckoutIsRefused(string $path): void
    {
        $this->expectException(PathOutsideCheckout::class);

        $this->fixture->checkout->resolve($path);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function linksIntoTheGitDirectory(): iterable
    {
        yield 'directory link' => ['alias', '.git', 'alias/config'];
        yield 'file link' => ['config-link', '.git/config', 'config-link'];
        yield 'link to the directory itself' => ['alias', '.git', 'alias'];
    }

    #[DataProvider('linksIntoTheGitDirectory')]
    public function testALinkIntoTheGitDirectoryIsRefused(string $link, string $target, string $path): void
    {
        $this->fixture->link($link, $target);
        $this->expectException(PathOutsideCheckout::class);

        $this->fixture->checkout->resolve($path);
    }

    public function testAMissingPathIsNotAnEscape(): void
    {
        $this->expectException(PathNotInCheckout::class);

        $this->fixture->checkout->resolve('src/Missing.php');
    }

    public function testACheckoutThatIsNotADirectoryIsRefused(): void
    {
        $this->expectException(CheckoutNotADirectory::class);

        new Checkout('/nonexistent/checkout');
    }

    protected function setUp(): void
    {
        $this->fixture = new ReviewCheckout();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }
}
