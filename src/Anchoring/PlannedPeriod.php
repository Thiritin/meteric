<?php

declare(strict_types=1);

namespace Meteric\Anchoring;

use Meteric\Enums\LineKind;
use Meteric\Support\Period;

/** One period the first invoice should bill, with how it's billed. */
final class PlannedPeriod
{
    public function __construct(
        public readonly Period $period,
        public readonly LineKind $kind,
        public readonly bool $prorated = false,
        public readonly bool $free = false,
    ) {}

    /** @return array{covers:array{0:string,1:string},kind:string,prorated:bool,free:bool} */
    public function toArray(): array
    {
        return [
            'covers' => $this->period->toArray(),
            'kind' => $this->kind->value,
            'prorated' => $this->prorated,
            'free' => $this->free,
        ];
    }
}
