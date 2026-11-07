<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Deprecation\Fixtures;

// Because a vendor package in these fixtures is code outside the checkout: a function that deprecates itself and a class whose load-time code calls it.
final readonly class DeprecatedVendorPackage
{
    public const string FUNCTION_VARIABLE = 'INTAKT_DEPRECATED_VENDOR_FUNCTION';

    public const string CONSUMER = 'IntaktVendorFixture\Consumer';

    private const string FUNCTION = 'intakt_deprecated_vendor_function';

    private function __construct(private string $directory)
    {
    }

    public static function writtenOutsideTheCheckout(): self
    {
        $directory = sys_get_temp_dir().'/intakt-deprecated-vendor-'.bin2hex(random_bytes(6));
        mkdir($directory);
        file_put_contents(
            $directory.'/Api.php',
            '<?php function '.self::FUNCTION."(): void { trigger_deprecation('intakt/fixture', '1.0', '".self::FUNCTION."() is deprecated'); }",
        );
        file_put_contents($directory.'/Consumer.php', '<?php namespace IntaktVendorFixture; '.self::FUNCTION.'(); final class Consumer {}');
        require $directory.'/Api.php';
        putenv(self::FUNCTION_VARIABLE.'='.self::FUNCTION);

        return new self($directory);
    }

    public function consumerFile(): string
    {
        return $this->directory.'/Consumer.php';
    }

    public function remove(): void
    {
        putenv(self::FUNCTION_VARIABLE);
        unlink($this->directory.'/Api.php');
        unlink($this->directory.'/Consumer.php');
        rmdir($this->directory);
    }
}
