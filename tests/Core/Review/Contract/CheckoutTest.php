<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Checkout;
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
final class CheckoutTest extends TestCase
{
    public function testAFileInsideTheCheckoutResolvesToItsRealPath(): void
    {
        $fixture = new ReviewCheckout();

        $real = $fixture->checkout->resolve('src/Cart.php');

        self::assertSame('src/Cart.php', $fixture->checkout->relative($real));
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
        $fixture = new ReviewCheckout();
        $this->expectException(PathOutsideCheckout::class);

        $fixture->checkout->resolve($path);
    }

    public function testAMissingPathIsNotAnEscape(): void
    {
        $fixture = new ReviewCheckout();
        $this->expectException(PathNotInCheckout::class);

        $fixture->checkout->resolve('src/Missing.php');
    }

    public function testACheckoutThatIsNotADirectoryIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Checkout('/nonexistent/checkout');
    }
}
