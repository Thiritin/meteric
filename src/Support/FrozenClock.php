<?php

declare(strict_types=1);

namespace Meteric\Support;

use Carbon\CarbonImmutable;
use Meteric\Contracts\Clock;

/** Test/quote clock pinned to a fixed instant. */
final class FrozenClock implements Clock
{
    public function __construct(private CarbonImmutable $at) {}

    public static function at(string|CarbonImmutable $at): self
    {
        return new self($at instanceof CarbonImmutable ? $at : CarbonImmutable::parse($at));
    }

    public function now(): CarbonImmutable
    {
        return $this->at;
    }

    public function set(CarbonImmutable $at): void
    {
        $this->at = $at;
    }
}
