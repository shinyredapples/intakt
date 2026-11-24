<?php

declare(strict_types=1);

namespace Intakt\Core\Doctor\Contract;

use NoDiscard;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

// An interface because the checks live in the modules that own the piece they judge; a check must be read-only and end within seconds, since the doctor runs when the stack is half down.
#[AutoconfigureTag(DoctorServices::CHECK_TAG)]
interface HealthCheck
{
    public function name(): string;

    #[NoDiscard('a check that is run and not looked at proves nothing')]
    public function run(): CheckResult;
}
