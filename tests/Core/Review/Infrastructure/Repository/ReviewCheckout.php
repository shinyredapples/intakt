<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure\Repository;

use Intakt\Core\Review\Contract\Checkout;

final readonly class ReviewCheckout
{
    private string $outside;

    public Checkout $checkout;

    public function __construct()
    {
        $base = sys_get_temp_dir().'/intakt-checkout-'.bin2hex(random_bytes(6));
        $this->outside = $base.'/outside';
        mkdir($base.'/head/src', recursive: true);
        mkdir($this->outside);
        mkdir($base.'/head/.git');
        file_put_contents($base.'/head/README.md', "# Sample\nfirst line\n");
        file_put_contents($base.'/head/src/Cart.php', "<?php\n// total the cart\nfinal class Cart {}\n");
        file_put_contents($base.'/head/.git/config', 'secret-config');
        file_put_contents($this->outside.'/secret.txt', 'outside secret');
        symlink($this->outside, $base.'/head/escape');
        $this->checkout = new Checkout($base.'/head');
        $this->base = $base;
    }

    private string $base;

    public function __destruct()
    {
        exec('rm -rf '.escapeshellarg($this->base));
    }
}
