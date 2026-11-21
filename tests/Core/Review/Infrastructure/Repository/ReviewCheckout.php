<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure\Repository;

use Intakt\Core\Review\Contract\Checkout;
use Symfony\Component\Filesystem\Filesystem;

final readonly class ReviewCheckout
{
    public Checkout $checkout;

    private string $base;

    private string $head;

    public function __construct()
    {
        $this->base = sys_get_temp_dir().'/intakt-checkout-'.bin2hex(random_bytes(6));
        $this->head = $this->base.'/head';
        $outside = $this->base.'/outside';
        mkdir($this->head.'/src', recursive: true);
        mkdir($outside);
        mkdir($this->head.'/.git');
        file_put_contents($this->head.'/README.md', "# Sample\nfirst line\n");
        file_put_contents($this->head.'/src/Cart.php', "<?php\n// total the cart\nfinal class Cart {}\n");
        file_put_contents($this->head.'/.git/config', 'secret-config');
        file_put_contents($outside.'/secret.txt', 'outside secret');
        symlink($outside, $this->head.'/escape');
        $this->checkout = new Checkout($this->head);
    }

    public function write(string $path, string $content): void
    {
        $file = $this->head.'/'.$path;

        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), recursive: true);
        }

        file_put_contents($file, $content);
    }

    public function link(string $path, string $target): void
    {
        symlink($target, $this->head.'/'.$path);
    }

    public function pipe(string $path): void
    {
        posix_mkfifo($this->head.'/'.$path, 0o600);
    }

    public function chmod(string $path, int $mode): void
    {
        chmod($this->head.'/'.$path, $mode);
    }

    public function remove(): void
    {
        new Filesystem()->remove($this->base);
    }
}
