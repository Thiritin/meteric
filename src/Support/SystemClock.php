<?php

declare(strict_types=1);

namespace Meteric\Support;

use Carbon\CarbonImmutable;
use Meteric\Contracts\Clock;

final class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
