<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Deprecation\Fixtures;

use LogicException;

final class CallsADeprecatedVendorFunctionWhileLoading
{
}

// Because this file must call a deprecated vendor function while it loads, and the function only exists once DeprecatedVendorPackage wrote it outside the checkout, its name is read from the environment so static analysis sees no undefined function.
$deprecated = getenv(DeprecatedVendorPackage::FUNCTION_VARIABLE);

if (!is_callable($deprecated)) {
    throw new LogicException('The deprecated vendor function is not written and named in the environment.');
}

$deprecated();
