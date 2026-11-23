<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

// Its own kind, because its message is written for the person who pressed the button and the error page may show it, unlike the framework's messages.
final class FormIsRefused extends BadRequestHttpException
{
}
