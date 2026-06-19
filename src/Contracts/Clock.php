<?php

declare(strict_types=1);

namespace Meteric\Contracts;

use Carbon\CarbonImmutable;

/**
 * Injected time source. Calculators NEVER call now() directly so proration and
 * quoting are deterministic and testable to the second.
 */
interface Clock
{
    public function now(): CarbonImmutable;
}
