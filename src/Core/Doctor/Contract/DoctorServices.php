<?php

declare(strict_types=1);

namespace Intakt\Core\Doctor\Contract;

// The container ids of this module, so config and code never spell one twice.
final readonly class DoctorServices
{
    public const string CHECK_TAG = 'intakt.doctor.check';
}
